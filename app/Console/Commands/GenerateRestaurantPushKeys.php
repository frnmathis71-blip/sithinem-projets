<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateRestaurantPushKeys extends Command
{
    protected $signature = 'restaurant:push-keys {--subject= : Public HTTPS contact URL or mailto address}';

    protected $description = 'Generate VAPID keys in .env without printing or replacing existing credentials';

    public function handle(): int
    {
        $path = app()->environmentFilePath();
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->error('Le fichier .env est introuvable.');

            return self::FAILURE;
        }
        if (preg_match('/^VAPID_(PUBLIC|PRIVATE)_KEY=\S+/m', $contents)) {
            $this->error('Des clés existent déjà. Elles sont conservées pour ne pas invalider les abonnements.');

            return self::FAILURE;
        }
        $subject = $this->option('subject');
        if (! is_string($subject) || ! preg_match('~^(https://[^\s]+|mailto:[^\s@]+@[^\s@]+)$~', $subject)) {
            $this->error('Fournissez --subject avec une URL HTTPS publique ou une adresse mailto.');

            return self::FAILURE;
        }
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable) {
            $this->error('OpenSSL ne peut pas générer les clés. Sous Windows, définissez OPENSSL_CONF dans le terminal avant de lancer PHP (voir README).');

            return self::FAILURE;
        }
        foreach (['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey'], 'VAPID_SUBJECT' => $subject] as $key => $value) {
            $line = $key.'="'.$value.'"';
            $contents = preg_match('/^'.$key.'=.*$/m', $contents) ? preg_replace_callback('/^'.$key.'=.*$/m', fn () => $line, $contents) : $contents.PHP_EOL.$line.PHP_EOL;
        }
        file_put_contents($path, $contents, LOCK_EX);
        $this->call('config:clear');
        $this->info('Clés enregistrées dans .env. Activez les notifications depuis le tableau de bord, en HTTPS.');

        return self::SUCCESS;
    }
}
