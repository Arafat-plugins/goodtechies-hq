<?php

namespace App\Support;

enum ProjectType: string
{
    case Seo = 'seo';
    case WebsiteMaintenance = 'website_maintenance';
    case WebsiteDevelopment = 'website_development';
    case WooCommerce = 'woocommerce';
    case WebApplication = 'web_application';
    case Marketing = 'marketing';
    case Internal = 'internal';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Seo => 'SEO',
            self::WebsiteMaintenance => 'Website Maintenance',
            self::WebsiteDevelopment => 'Website Development',
            self::WooCommerce => 'WooCommerce',
            self::WebApplication => 'Web Application',
            self::Marketing => 'Marketing',
            self::Internal => 'Internal',
            self::Other => 'Other',
        };
    }
}
