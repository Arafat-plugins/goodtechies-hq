/**
 * Polish 051: a project's round icon in the Clients list (image 2) — a white symbol on a solid
 * colour, chosen by the project's type.
 */
import { Box, Globe, LayoutGrid, Megaphone, ShoppingCart } from '@lucide/vue';
import type { Component } from 'vue';

const ICONS: Record<string, { icon: Component; solid: string }> = {
    website_development: { icon: Globe, solid: 'bg-status-progress-fg text-background' },
    website_maintenance: { icon: Globe, solid: 'bg-status-progress-fg text-background' },
    seo: { icon: LayoutGrid, solid: 'bg-status-done-fg text-background' },
    marketing: { icon: Megaphone, solid: 'bg-status-waiting-fg text-background' },
    woocommerce: { icon: ShoppingCart, solid: 'bg-status-review-fg text-background' },
    web_application: { icon: Box, solid: 'bg-status-changes-fg text-background' },
};

const FALLBACK = { icon: Box, solid: 'bg-status-backlog-fg text-background' };

export function projectIcon(type: string | null | undefined): { icon: Component; solid: string } {
    return ICONS[type ?? ''] ?? FALLBACK;
}
