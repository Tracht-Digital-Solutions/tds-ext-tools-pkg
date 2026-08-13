<?php
/**
 * API documentation for this module's routes — consumed through `ApiDocSource`
 * and rendered in the admin frontend's API reference (`GET /wiki.json`).
 *
 * `pattern` must match the Slim pattern in `register()` VERBATIM, inline regex
 * included: it is the join key for the route introspection.
 * `php/tests/ToolsApiDocsTest.php` fails the build if the documented set and the
 * registered set drift apart in either direction.
 */

declare(strict_types=1);

return [
    [
        'method' => 'GET',
        'pattern' => '/tools/catalog',
        'tag' => 'Öffentlich',
        'summary' => 'Der zusammengeführte Tool-Katalog samt AdSense-Konfiguration',
        'description' => 'Unauthentifiziert: `tools.tracht-digital.de` backt diese Antwort '
            . 'beim Build in die statische Seite. Geliefert wird die Vereinigung aus '
            . 'dem, was die Tool-Pakete gemeldet haben, und den Übersteuerungen aus der '
            . 'Verwaltung (aktiv, Login nötig, Premium, Preis).',
        'auth' => 'public',
        'responses' => [
            [
                'status' => 200,
                'description' => '`{tools: [{id, slug, name, enabled, requires_login, is_premium, price_cents}], ads: {enabled, client, slots}}`',
            ],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tools/registry',
        'tag' => 'Öffentlich',
        'summary' => 'Der Site-Build meldet seine komponierten Tool-Pakete',
        'description' => 'Die Richtung, in der die Tool-Liste fließt: die Pakete deklarieren '
            . 'die Tools, der Build von `tds-tools-frontend` meldet sie hierher, und die '
            . 'Verwaltung kann sie danach übersteuern. Kein Nutzer-Login, sondern ein '
            . 'gemeinsames Geheimnis — der Build hat keine Sitzung. Der Vergleich läuft '
            . 'über `hash_equals`, also zeitkonstant.',
        'auth' => 'token',
        'params' => [
            ['in' => 'body', 'name' => 'token', 'type' => 'string', 'description' => 'Das Sync-Token. Alternativ als `Authorization: Bearer`.'],
            ['in' => 'body', 'name' => 'tools', 'type' => 'array', 'required' => true, 'description' => 'Die komponierten Tools aus den Paketen.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, synced: <Anzahl>}`'],
            ['status' => 401, 'description' => 'Token fehlt oder stimmt nicht.'],
            ['status' => 503, 'description' => 'Kein Sync-Token konfiguriert — die Route ist damit abgeschaltet.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/tools',
        'tag' => 'Verwaltung',
        'summary' => 'Alle Tools mit ihren Übersteuerungen',
        'permission' => 'tools:manage',
        'responses' => [
            ['status' => 200, 'description' => '`{tools: [...]}` — gemeldeter Stand plus Übersteuerungen.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tools:manage`.'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/admin/tools/{id}',
        'tag' => 'Verwaltung',
        'summary' => 'Übersteuerung eines Tools setzen',
        'description' => '**Löst einen Rebuild der Tools-Seite aus.** Der Katalog ist in die '
            . 'statische Seite gebacken, eine Änderung wird also erst mit dem nächsten '
            . 'Build sichtbar — deshalb feuert die Route ihn selbst. `{id}` hat '
            . 'bewusst kein Zahlenmuster: Tool-Ids sind Slugs.',
        'permission' => 'tools:manage',
        'params' => [
            ['in' => 'path', 'name' => 'id', 'type' => 'string', 'required' => true, 'description' => 'Tool-Id (Slug, keine Zahl).'],
            ['in' => 'body', 'name' => 'enabled', 'type' => 'bool', 'description' => 'Tool auf der Seite anzeigen.'],
            ['in' => 'body', 'name' => 'requires_login', 'type' => 'bool', 'description' => 'Nur für angemeldete Nutzer.'],
            ['in' => 'body', 'name' => 'is_premium', 'type' => 'bool', 'description' => 'Kostenpflichtig (setzt `requires_login` voraus).'],
            ['in' => 'body', 'name' => 'price_cents', 'type' => 'int', 'description' => 'Einmalpreis in Cent.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true}` — Rebuild wurde angestoßen.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tools:manage`.'],
            ['status' => 404, 'description' => 'Unbekannte Tool-Id, oder nichts zu ändern.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/tools/rebuild',
        'tag' => 'Verwaltung',
        'summary' => 'Rebuild der Tools-Seite von Hand auslösen',
        'description' => 'Für den Fall, dass der automatische Rebuild aus einer Änderung '
            . 'heraus nicht durchgelaufen ist.',
        'permission' => 'tools:manage',
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true}`'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tools:manage`.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tools/summary',
        'tag' => 'Verwaltung',
        'summary' => 'Kennzahlen des Katalogs für das Dashboard-Widget',
        'permission' => 'tools:manage',
        'responses' => [
            ['status' => 200, 'description' => 'Zählwerte je Kategorie plus `ads` (ob AdSense aktiv ist).'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 403, 'description' => 'Kein `tools:manage`.'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/tools/entitlement',
        'tag' => 'Premium',
        'summary' => 'Prüfen, ob der angemeldete Nutzer ein Premium-Tool nutzen darf',
        'description' => 'Die Freischaltung hängt an `app_user_id`, nicht an Browser oder '
            . 'Gerät. Admins dürfen jedes Premium-Tool ohne Kauf. Die Premium-Tools '
            . 'laufen im Browser, die Prüfung ist also eine Bezahlschranke und kein '
            . 'Kopierschutz.',
        'auth' => 'session',
        'params' => [
            ['in' => 'query', 'name' => 'tool', 'type' => 'string', 'required' => true, 'description' => 'Tool-Id.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{entitled: bool, authenticated: true}`'],
            ['status' => 401, 'description' => '`{entitled: false, authenticated: false}` — die Seite zeigt daraufhin den Login-Hinweis statt eines Fehlers.'],
            ['status' => 422, 'description' => '`tool` fehlt.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tools/checkout',
        'tag' => 'Premium',
        'summary' => 'Stripe-Checkout für ein Premium-Tool starten',
        'description' => 'Preis und Name kommen aus dem Katalog, nie aus der Anfrage — sonst '
            . 'könnte der Browser seinen eigenen Preis vorgeben. Die Nutzer-Id reist als '
            . '`client_reference_id` mit, damit der Webhook die Freischaltung zuordnen '
            . 'kann. Ein Stripe-Fehler wird als 502 durchgereicht.',
        'auth' => 'session',
        'params' => [
            ['in' => 'body', 'name' => 'tool', 'type' => 'string', 'required' => true, 'description' => 'Tool-Id. Muss Premium sein und einen Preis > 0 haben.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{url}` — die Checkout-Seite von Stripe.'],
            ['status' => 400, 'description' => 'Unbekanntes Tool, nicht Premium, oder Preis 0.'],
            ['status' => 401, 'description' => 'Keine Sitzung.'],
            ['status' => 409, 'description' => 'Bereits freigeschaltet.'],
            ['status' => 502, 'description' => 'Stripe hat die Session abgelehnt.'],
            ['status' => 503, 'description' => 'Kein Stripe Secret Key konfiguriert.'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/tools/stripe-webhook',
        'tag' => 'Premium',
        'summary' => 'Stripe-Webhook (Kauf abgeschlossen → Freischaltung)',
        'description' => 'Unauthentifiziert, aber signaturgeprüft. Reagiert auf '
            . '`checkout.session.completed` und legt die Freischaltung in '
            . '`tools_entitlement` an. Andere Ereignistypen werden mit 200 quittiert, '
            . 'damit Stripe sie nicht endlos wiederholt.',
        'auth' => 'token',
        'params' => [
            ['in' => 'header', 'name' => 'Stripe-Signature', 'type' => 'string', 'required' => true, 'description' => 'Von Stripe erzeugte Signatur über den rohen Body.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{received: true}` — auch für nicht behandelte Ereignistypen.'],
            ['status' => 400, 'description' => 'Signatur ungültig.'],
            ['status' => 503, 'description' => 'Kein Webhook-Secret konfiguriert.'],
        ],
    ],
];
