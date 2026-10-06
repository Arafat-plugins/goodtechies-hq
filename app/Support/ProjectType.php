<?php

namespace App\Support;

use App\Services\SettingsService;
use Throwable;

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

    /**
     * The name shown everywhere: the Admin's own name for this type when they gave one
     * (polish 026, `project_type_labels`), otherwise the built-in one.
     */
    public function label(): string
    {
        try {
            $custom = app(SettingsService::class)->get('project_type_labels');
        } catch (Throwable) {
            $custom = null;
        }

        $name = is_array($custom) ? trim((string) ($custom[$this->value] ?? '')) : '';

        return $name !== '' ? $name : $this->defaultLabel();
    }

    public function defaultLabel(): string
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
