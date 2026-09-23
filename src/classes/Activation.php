<?php

namespace Kirbydesk\Explorer;

use Kirby\Cms\App;
use Kirby\Data\Json;
use Kirby\Exception\LogicException;
use Kirby\Http\Remote;
use Kirby\Http\Url;

/**
 * The explorer is free, but every domain is activated once – so we know
 * where it runs. A key is an Ed25519 signature of the domain, made on
 * activate.kirbyexplorer.com; the plugin carries the public key and checks it here,
 * without ever asking again.
 *
 * Until a site is activated, the panel shows a dialog – but only from
 * the tenth visit of a session on, and never on a local domain.
 */
final class Activation
{
    /** kirbydesk.com signs with the private half of this */
    private const PUBLIC_KEY = 'Guh/AW4/fqQf1cDPwYCdPMoqoqXiBIVXSELWDEGxHdI=';

    /** where a key is fetched, one click, no account */
    private const SERVER = 'https://activate.kirbyexplorer.com/api';

    /** visits of one session before the dialog shows up */
    private const REMIND_AFTER = 10;

    /**
     * For trying it out on a local domain – in config.php:
     *
     *   'kirbydesk.kirby-explorer.activation' => [
     *       'local' => true,   // ask on .test, localhost … as well
     *       'after' => 1,      // after this many visits
     *   ],
     */
    private function setting(string $key, mixed $default): mixed
    {
        $options = $this->kirby->option('kirbydesk.kirby-explorer.activation');
        return is_array($options) ? ($options[$key] ?? $default) : $default;
    }

    /** these run on someone's machine, not on a site */
    private const LOCAL = ['localhost', '127.0.0.1', '::1'];
    private const LOCAL_ENDINGS = ['.test', '.local', '.localhost', '.ddev.site', '.dev'];

    public function __construct(private readonly App $kirby)
    {
    }

    /** The domain the site runs on, without port or www */
    public function domain(): string
    {
        $host = Url::toObject($this->kirby->url('index'))->host() ?? '';
        return strtolower(preg_replace('/^www\./', '', $host));
    }

    /** Development happens on local domains: nothing to activate there */
    public function isLocal(): bool
    {
        if ($this->setting('local', false) === true) {
            return false;
        }

        $domain = $this->domain();

        if ($domain === '' || in_array($domain, self::LOCAL, true)) {
            return true;
        }

        foreach (self::LOCAL_ENDINGS as $ending) {
            if (str_ends_with($domain, $ending)) return true;
        }

        return false;
    }

    /** The stored key: the option wins over the license file */
    public function key(): ?string
    {
        $option = $this->kirby->option('kirbydesk.kirby-explorer.license');
        if (is_string($option) && $option !== '') return $option;

        $file = $this->read();
        return is_string($file['key'] ?? null) ? $file['key'] : null;
    }

    /** Is the key a valid signature of this domain? */
    public function isActive(): bool
    {
        $key = $this->key();

        return $key !== null && $this->verify($key);
    }

    /** Does this key sign this domain – and no other? */
    public function verify(string $key): bool
    {
        $signature = base64_decode(trim($key), true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            $signature,
            $this->domain(),
            base64_decode(self::PUBLIC_KEY)
        );
    }

    /**
     * Take a key that arrived by mail. It is only kept if it really is
     * this domain's signature, so a stale or mistyped key changes
     * nothing – and a key for someone else's domain is refused.
     */
    public function apply(string $key): bool
    {
        foreach ($this->readings($key) as $candidate) {
            if ($this->verify($candidate) === true) {
                $this->store($candidate);
                return true;
            }
        }

        return false;
    }

    /**
     * How a key may arrive. Copied out of a mail it can carry the line
     * breaks the mail programme put in; and where a link lost its
     * encoding on the way, the pluses of the base64 come back as spaces.
     * Both are worth a second look before we call a key invalid.
     */
    private function readings(string $key): array
    {
        $whitespace = '/\s+/';

        return array_filter(array_unique([
            preg_replace($whitespace, '', $key) ?? '',
            preg_replace($whitespace, '', str_replace(' ', '+', trim($key))) ?? '',
        ]));
    }

    /**
     * Ask the activation server for this domain's key and store it – from
     * the dialog, one click, nothing to type.
     *
     * A challenge goes along: a random number we put down here and that
     * the server reads back from this site (see the `verify` route). Only
     * someone who really runs this domain can answer it, so made-up
     * domains do not get a key.
     */
    public function activate(): array
    {
        $domain    = $this->domain();
        $challenge = bin2hex(random_bytes(16));
        $this->putChallenge($challenge);

        $response = Remote::request(self::SERVER, [
            'method'  => 'POST',
            'data'    => [
                'domain'    => $domain,
                'challenge' => $challenge,
            ],
            'timeout' => 15,
        ]);

        if ($response->code() !== 200) {
            $this->putChallenge(null);

            // the server says why (unknown domain, check failed …)
            $message = $response->json()['error'] ?? null;
            throw new LogicException(
                message: is_string($message) ? $message : 'The activation server answered with ' . $response->code()
            );
        }

        $key = $response->json()['key'] ?? null;
        if (is_string($key) === false || $key === '') {
            throw new LogicException(message: 'The activation server sent no key');
        }

        $this->store($key);
        $this->putChallenge(null);

        if ($this->isActive() === false) {
            throw new LogicException(message: 'The key does not belong to ' . $domain);
        }

        return ['activated' => true, 'domain' => $domain];
    }

    /**
     * Count this visit and say whether the dialog is due. The count
     * lives in the session, so every login starts over – and after each
     * reminder it starts over as well.
     */
    public function visit(): bool
    {
        if ($this->isLocal() === true || $this->isActive() === true) {
            return false;
        }

        $session = $this->kirby->session();
        $visits  = (int) $session->get('kirbydesk.explorer.visits', 0) + 1;

        // asked and put off: start over, so the next reminder is ten
        // visits away and not on every single one
        if ($visits >= (int) $this->setting('after', self::REMIND_AFTER)) {
            $session->set('kirbydesk.explorer.visits', 0);
            return true;
        }

        $session->set('kirbydesk.explorer.visits', $visits);
        return false;
    }

    /** What the panel needs to know: shown in the dialog */
    public function status(): array
    {
        return [
            'active' => $this->isActive(),
            'local'  => $this->isLocal(),
            'domain' => $this->domain(),
        ];
    }

    /** The number the server is about to ask for; null clears it */
    public function putChallenge(?string $challenge): void
    {
        $file = $this->challengeFile();

        if ($challenge === null) {
            @unlink($file);
            return;
        }

        if (is_dir(dirname($file)) === false) {
            mkdir(dirname($file), 0755, true);
        }

        file_put_contents($file, $challenge . "\n" . time());
    }

    /** What the server asks for – valid for a few minutes */
    public function challenge(): ?string
    {
        $file = $this->challengeFile();
        if (is_file($file) === false) return null;

        [$challenge, $time] = array_pad(explode("\n", (string) file_get_contents($file), 2), 2, '0');

        if ((int) $time < time() - 300) {
            @unlink($file);
            return null;
        }

        return $challenge !== '' ? $challenge : null;
    }

    private function challengeFile(): string
    {
        return $this->kirby->root('cache') . '/explorer/challenge';
    }

    private function file(): string
    {
        return $this->kirby->root('config') . '/.kirby-explorer';
    }

    private function read(): array
    {
        try {
            return is_file($this->file()) ? Json::read($this->file()) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function store(string $key): void
    {
        Json::write($this->file(), ['domain' => $this->domain(), 'key' => $key]);
    }
}
