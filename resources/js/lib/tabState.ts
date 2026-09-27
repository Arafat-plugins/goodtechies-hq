import { ref, watch, type Ref } from 'vue';
import { queryParam, syncQuery } from '@/lib/tableState';

/**
 * Which tab a detail page is showing, kept in the URL — decision 2-51.
 *
 * `tab` was a plain `ref('overview')` on the project and client detail pages, so a reload dropped
 * back to Overview and there was no way to send a colleague a link to a project's **Files**. Every
 * write on those pages redirects `back()`, which means uploading a file bounced the reader out of
 * the tab they uploaded it into.
 *
 * ## It is the query string, and the query string only
 *
 * The same split `lib/tableState.ts` states: the query string holds everything that changes what
 * is on screen, so any view is a URL somebody can paste into a message; `localStorage` holds only
 * what is personal to the viewer. Which tab is open is the first kind — and it is deliberately not
 * remembered per person, because "the tab I was last on" is exactly what makes a shared link open
 * somewhere else for the person you sent it to.
 *
 * **`replaceState`, not a history entry** (that is `syncQuery`'s doing). Seven tabs on a project
 * page would otherwise stack seven entries and Back would walk the reader through them instead of
 * returning to the list they came from. Filters make the same trade in `tableState`.
 *
 * **The default tab is absent from the URL**, not `?tab=overview`: the canonical address of a
 * project is its own path, and a parameter that only ever restates the default is noise in a link.
 *
 * ## A tab this reader has no business on is not honoured
 *
 * `allowed` is the tabs that are actually rendered — which on a project means the Finance tab is in
 * it only when `permissions.can_view_finance` is true. So `?tab=finance` sent to somebody who may
 * not see it opens Overview instead of selecting a tab whose panel is not in the DOM. It is not a
 * privacy rule (the payload has no finance keys for them at all, which is the rule); it is what
 * stops a shared link rendering an empty page.
 *
 * An unknown or refused value is also rewritten out of the address bar on arrival, so what the URL
 * says and what the screen shows never disagree.
 */
export function useUrlTab(allowed: readonly string[], fallback: string): Ref<string> {
    const asked = queryParam('tab');
    const opening = asked !== null && allowed.includes(asked) ? asked : fallback;

    const tab = ref(opening);

    // Normalise on arrival: `?tab=nonsense`, `?tab=finance` from somebody who may not see it, and
    // `?tab=overview` typed by hand all become the address the screen is actually showing.
    if (asked !== opening) {
        syncQuery({ tab: opening === fallback ? null : opening });
    }

    watch(tab, (value) => syncQuery({ tab: value === fallback ? null : value }));

    return tab;
}
