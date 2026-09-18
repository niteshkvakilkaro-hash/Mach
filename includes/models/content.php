<?php
/**
 * Read-only website content: categories, services, FAQs, testimonials, locations, pages.
 * Results are memoised per request so templates can call these freely.
 */

function get_categories(): array
{
    static $cache = null;
    return $cache ??= db_all(
        'SELECT id, name, slug, icon, short_description, appliance_types
         FROM service_categories WHERE is_active = 1 ORDER BY sort_order, name'
    );
}

/** All active services (with category name), ordered by category then service order. */
function get_services(): array
{
    static $cache = null;
    return $cache ??= db_all(
        "SELECT s.id, s.category_id, s.name, s.slug, s.icon, s.short_description, s.starting_price,
                s.price_note, s.duration, s.warranty, s.is_featured, s.is_emergency, s.updated_at,
                c.name AS category_name, c.slug AS category_slug
         FROM services s JOIN service_categories c ON c.id = s.category_id
         WHERE s.status = 'active' AND c.is_active = 1
         ORDER BY c.sort_order, s.sort_order, s.name"
    );
}

/** Categories with their services attached (two queries, no N+1). */
function get_categories_with_services(): array
{
    $byCat = [];
    foreach (get_services() as $s) {
        $byCat[$s['category_id']][] = $s;
    }
    $out = [];
    foreach (get_categories() as $c) {
        if (!empty($byCat[$c['id']])) {
            $c['services'] = $byCat[$c['id']];
            $out[] = $c;
        }
    }
    return $out;
}

/** One card per category for the home page: the first featured service represents the category. */
function get_home_service_cards(int $limit = 8): array
{
    $cards = [];
    foreach (get_categories_with_services() as $c) {
        $featured = array_values(array_filter($c['services'], fn($s) => (int) $s['is_featured'] === 1));
        $primary = $featured[0] ?? $c['services'][0];
        $minPrice = min(array_map(fn($s) => (float) ($s['starting_price'] ?? PHP_INT_MAX), $c['services']));
        $cards[] = $primary + ['category_icon' => $c['icon'], 'category_desc' => $c['short_description'], 'min_price' => $minPrice];
        if (count($cards) >= $limit) {
            break;
        }
    }
    return $cards;
}

function get_service_by_slug(string $slug): ?array
{
    return db_one(
        "SELECT s.*, c.name AS category_name, c.slug AS category_slug, c.appliance_types
         FROM services s JOIN service_categories c ON c.id = s.category_id
         WHERE s.slug = ? AND s.status = 'active' AND c.is_active = 1",
        [$slug]
    );
}

function get_service_problems(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db_all('SELECT service_id, name FROM service_problems WHERE is_active = 1 ORDER BY sort_order, name') as $row) {
            $cache[$row['service_id']][] = $row['name'];
        }
    }
    return $cache;
}

function get_service_prices(int $serviceId): array
{
    return db_all(
        'SELECT label, price, price_type, note FROM service_prices
         WHERE service_id = ? AND is_active = 1 ORDER BY sort_order, id',
        [$serviceId]
    );
}

function get_testimonials(int $limit = 6): array
{
    return db_all(
        'SELECT t.customer_name, t.location, t.rating, t.review, t.photo, s.name AS service_name
         FROM testimonials t LEFT JOIN services s ON s.id = t.service_id
         WHERE t.is_active = 1 ORDER BY t.sort_order, t.id DESC LIMIT ' . max(1, $limit)
    );
}

/** General FAQs, or service FAQs followed by general ones. */
function get_faqs(?int $serviceId = null, int $limit = 8): array
{
    if ($serviceId === null) {
        return db_all(
            'SELECT question, answer FROM faqs WHERE is_active = 1 AND service_id IS NULL
             ORDER BY sort_order, id LIMIT ' . max(1, $limit)
        );
    }
    return db_all(
        'SELECT question, answer FROM faqs WHERE is_active = 1 AND (service_id = ? OR service_id IS NULL)
         ORDER BY service_id IS NULL, sort_order, id LIMIT ' . max(1, $limit),
        [$serviceId]
    );
}

/** Active cities, each with its active areas. */
function get_locations(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $rows = db_all('SELECT id, parent_id, type, name, slug, pincode FROM locations WHERE is_active = 1 ORDER BY sort_order, name');
    $cities = [];
    foreach ($rows as $r) {
        if ($r['type'] === 'city') {
            $cities[$r['id']] = $r + ['areas' => []];
        }
    }
    foreach ($rows as $r) {
        if ($r['type'] === 'area' && isset($cities[$r['parent_id']])) {
            $cities[$r['parent_id']]['areas'][] = $r;
        }
    }
    return $cache = array_values($cities);
}

function get_page(string $slug): ?array
{
    return db_one('SELECT * FROM pages WHERE slug = ? AND is_active = 1', [$slug]);
}

/** Compact catalogue for the booking form's dependent dropdowns (embedded as JSON). */
function booking_form_data(): array
{
    $problems = get_service_problems();
    return [
        'categories' => array_map(fn($c) => [
            'id'         => (int) $c['id'],
            'name'       => $c['name'],
            'appliances' => array_values(array_filter(array_map('trim', explode(',', (string) $c['appliance_types'])))),
        ], get_categories()),
        'services' => array_map(fn($s) => [
            'id'          => (int) $s['id'],
            'category_id' => (int) $s['category_id'],
            'name'        => $s['name'],
            'slug'        => $s['slug'],
            'problems'    => $problems[$s['id']] ?? [],
        ], get_services()),
        'cities' => array_map(fn($c) => [
            'id'    => (int) $c['id'],
            'name'  => $c['name'],
            'areas' => array_map(fn($a) => ['id' => (int) $a['id'], 'name' => $a['name'], 'pincode' => $a['pincode']], $c['areas']),
        ], get_locations()),
    ];
}

/** LocalBusiness JSON-LD used on the home and contact pages. */
function local_business_schema(): array
{
    $areas = [];
    foreach (get_locations() as $city) {
        $areas[] = ['@type' => 'City', 'name' => $city['name']];
    }
    $sameAs = array_values(array_filter([
        setting('social_facebook'), setting('social_instagram'), setting('social_youtube'),
        setting('social_x'), setting('social_linkedin'),
    ]));
    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'HomeAndConstructionBusiness',
        'name'       => setting('business_name'),
        'url'        => url(),
        'telephone'  => setting('phone'),
        'email'      => setting('email'),
        'address'    => ['@type' => 'PostalAddress', 'streetAddress' => setting('address'), 'addressLocality' => setting('city'), 'addressCountry' => 'IN'],
        'areaServed' => $areas,
        'priceRange' => '₹₹',
        'openingHours' => 'Mo-Su 08:00-21:00',
        'sameAs' => $sameAs,
    ];
}

function faq_schema(array $faqs): ?array
{
    if (!$faqs) {
        return null;
    }
    return [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => array_map(fn($f) => [
            '@type' => 'Question',
            'name'  => $f['question'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
        ], $faqs),
    ];
}
