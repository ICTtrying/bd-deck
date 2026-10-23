<?php

namespace App\Enums;

enum CredentialKind: string
{
    case SshPassword = 'ssh-password';
    case WordPressLogin = 'wordpress-login';
    case Database = 'database';
    case ApiKey = 'api-key';
    case HostingPanel = 'hosting-panel';
    case ShopifyStorePassword = 'shopify-store-password';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SshPassword => __('SSH/SFTP-wachtwoord'),
            self::WordPressLogin => __('WordPress-login'),
            self::Database => __('Database'),
            self::ApiKey => __('API-sleutel'),
            self::HostingPanel => __('Hostingpaneel'),
            self::ShopifyStorePassword => __('Shopify-winkelwachtwoord'),
            self::Other => __('Overig'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::SshPassword => 'terminal',
            self::WordPressLogin => 'wordpress',
            self::Database => 'database',
            self::ApiKey => 'key',
            self::HostingPanel => 'server',
            self::ShopifyStorePassword => 'shopify',
            self::Other => 'lock',
        };
    }
}
