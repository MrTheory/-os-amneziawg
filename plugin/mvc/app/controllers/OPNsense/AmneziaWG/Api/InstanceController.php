<?php

namespace OPNsense\AmneziaWG\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\AmneziaWG\Instance';
    protected static $internalModelName  = 'instance';

    // SEC-1: private key is stored in a protected file, not in config.xml.
    // The sentinel '::file::' in config.xml signals that the real key is on disk.
    // This prevents the key from appearing in config backups or xmlrpc sync.
    const PRIVKEY_FILE     = '/usr/local/etc/amnezia/private.key';
    const PRIVKEY_SENTINEL = '::file::';

    /**
     * Override getAction to mask the private_key field in API responses.
     * The UI receives a bullet placeholder so the field renders as non-empty,
     * but the actual key value is never transmitted to the browser.
     */
    public function getAction()
    {
        $result = parent::getAction();
        if (isset($result['instance']['private_key'])) {
            $stored = (string)$result['instance']['private_key'];
            // Show bullet placeholder when a key exists (sentinel or legacy plaintext)
            $result['instance']['private_key'] = $stored !== ''
                ? str_repeat(chr(0xE2) . chr(0x80) . chr(0xA2), 44)
                : '';
        }
        return $result;
    }

    /**
     * Override setAction to intercept private_key before it reaches the model.
     * If the submitted value is the bullet placeholder, keep existing key unchanged.
     * Otherwise write the new real key to the protected file and store the sentinel.
     */
    public function setAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }

        $body      = $this->request->getPost('instance', null, []);
        $submitted = trim($body['private_key'] ?? '');

        // Bullet placeholder (UTF-8 bullet 0xE2 0x80 0xA2) or empty = do not change key
        $isBullet  = strpos($submitted, "\xE2\x80\xA2") !== false;
        $isBlank   = $submitted === '';

        if (!$isBullet && !$isBlank) {
            // Validate that it's a proper WireGuard Base64 key (IMP-3)
            if (!preg_match('/^[A-Za-z0-9+\/]{43}=$/', $submitted)) {
                return [
                    'result' => 'failed',
                    'validations' => [
                        'instance.private_key' => 'Private key must be a valid Base64 WireGuard key (44 characters ending with =)'
                    ]
                ];
            }
            // Real key submitted — persist to protected file
            $this->writePrivateKey($submitted);
        } elseif ($isBlank) {
            // Blank submitted and no key file exists yet — reject
            if (!file_exists(self::PRIVKEY_FILE)) {
                return [
                    'result' => 'failed',
                    'validations' => [
                        'instance.private_key' => 'A private key is required. Use Generate Keypair or paste your key.'
                    ]
                ];
            }
        }
        // Always store the sentinel in config.xml, never the raw key
        $_POST['instance']['private_key'] = self::PRIVKEY_SENTINEL;

        return parent::setAction();
    }

    /**
     * Generate a new keypair via configd.
     * SEC-2: private_key is written to the protected file and NOT returned
     * in the API response. Only public_key is sent to the browser.
     */
    public function genKeyPairAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('amneziawg gen_keypair');
        $decoded = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['private_key'])) {
            return ['status' => 'error', 'message' => 'Failed to generate key pair'];
        }

        // SEC-2: store private key in file only, never expose it in the response
        $this->writePrivateKey($decoded['private_key']);

        // Persist sentinel into config.xml immediately so the model stays consistent
        $mdl = $this->getModel();
        $mdl->private_key = self::PRIVKEY_SENTINEL;
        $mdl->serializeToConfig();
        \OPNsense\Core\Config::getInstance()->save();

        return [
            'status'     => 'ok',
            'public_key' => $decoded['public_key'],
        ];
    }

    /**
     * Write the private key to the protected file with mode 0600.
     */
    private function writePrivateKey(string $key): void
    {
        $dir = dirname(self::PRIVKEY_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents(self::PRIVKEY_FILE, trim($key) . "\n");
        chmod(self::PRIVKEY_FILE, 0600);
    }
}
