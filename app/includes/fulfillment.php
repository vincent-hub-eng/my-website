<?php
/**
 * Africa Trade OS — Internal Proximity Routing, Distance ETA & PIN Verification Engine
 */

function ato_city_coordinates(): array {
    return [
        // Kenya major hubs
        'nairobi' => [-1.286389, 36.817223],
        'mombasa' => [-4.043477, 39.668206],
        'kisumu' => [-0.091702, 34.767956],
        'nakuru' => [-0.303099, 36.080026],
        'eldoret' => [0.514277, 35.269779],
        'thika' => [-1.038757, 37.083375],
        'kiambu' => [-1.171390, 36.835560],
        'machakos' => [-1.517684, 37.263415],
        'nyeri' => [-0.416667, 36.950000],
        'meru' => [0.046260, 37.655870],
        'isiolo' => [0.354620, 37.582180],
        'malindi' => [-3.219186, 40.116890],
        'kitale' => [1.015720, 35.006220],
        'garissa' => [-0.453229, 39.646010],
        'naivasha' => [-0.717178, 36.431026],
        'kisii' => [-0.681740, 34.766660],
        'kakamega' => [0.282731, 34.751863],
        'kericho' => [-0.367740, 35.283140],
        'nanyuki' => [0.016670, 37.072830],
        'namanga' => [-2.543330, 36.790000],
        'busia' => [0.460050, 34.111690],
        'malaba' => [0.635000, 34.275000],
        // Pan-African / AfCFTA hubs
        'kampala' => [0.347596, 32.582520],
        'entebbe' => [0.051184, 32.463708],
        'jinja' => [0.447857, 33.202612],
        'dar es salaam' => [-6.792354, 39.208328],
        'arusha' => [-3.386925, 36.682993],
        'mwanza' => [-2.516430, 32.917460],
        'dodoma' => [-6.162959, 35.751607],
        'kigali' => [-1.944073, 30.061885],
        'addis ababa' => [9.032000, 38.746890],
        'lagos' => [6.524379, 3.379206],
        'abuja' => [9.076479, 7.398574],
        'accra' => [5.603717, -0.186964],
        'johannesburg' => [-26.204103, 28.047305],
        'cape town' => [-33.924869, 18.424055],
        'cairo' => [30.044420, 31.235712],
        'casablanca' => [33.573110, -7.589843],
    ];
}

function ato_resolve_coords(?string $locationText, ?string $fallbackCity = null): ?array {
    $hubs = ato_city_coordinates();
    $candidates = array_filter([strtolower(trim((string)$locationText)), strtolower(trim((string)$fallbackCity))]);
    foreach ($candidates as $text) {
        if ($text === '') continue;
        if (isset($hubs[$text])) return ['lat' => $hubs[$text][0], 'lng' => $hubs[$text][1], 'hub' => ucwords($text)];
        foreach ($hubs as $city => $coords) {
            if (str_contains($text, $city)) {
                return ['lat' => $coords[0], 'lng' => $coords[1], 'hub' => ucwords($city)];
            }
        }
    }
    return null;
}

function ato_haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($earthRadius * $c * 1.22, 1);
}

function ato_calculate_transit_eta(PDO $pdo, array $order): array {
    $st = $pdo->prepare("SELECT bp.city AS seller_city, c.name AS seller_country FROM users u LEFT JOIN business_profiles bp ON bp.user_id=u.id LEFT JOIN countries c ON c.id=u.country_id WHERE u.id=?");
    $st->execute([$order['seller_id']]);
    $sellerLoc = $st->fetch() ?: [];

    $originText = trim(($sellerLoc['seller_city'] ?? '') . ' ' . ($sellerLoc['seller_country'] ?? ''));
    $destText   = trim((string)($order['delivery_address'] ?? ''));
    $isCrossBorder = (($order['trade_type'] ?? 'domestic') === 'cross_border');

    $originCoords = ato_resolve_coords($originText, $sellerLoc['seller_city'] ?? 'Nairobi');
    $destCoords   = ato_resolve_coords($destText, null);

    $originHub = $originCoords['hub'] ?? ($sellerLoc['seller_city'] ?: ($sellerLoc['seller_country'] ?: 'Origin Hub'));
    $destHub   = $destCoords['hub'] ?? (mb_substr($destText, 0, 40) ?: 'Destination');

    if ($originCoords && $destCoords) {
        $distanceKm = max(5.0, ato_haversine_km($originCoords['lat'], $originCoords['lng'], $destCoords['lat'], $destCoords['lng']));
    } else {
        $distanceKm = $isCrossBorder ? 650.0 : 45.0;
    }

    $avgSpeed = $distanceKm <= 30.0 ? 28.0 : 55.0;
    $transitHours = ($distanceKm / $avgSpeed) + 2.0;
    if ($isCrossBorder) {
        $transitHours += 36.0;
    }

    $etaHours = max(2, (int)ceil($transitHours));
    $etaTs = time() + ($etaHours * 3600);
    $etaDatetime = date('Y-m-d H:i:s', $etaTs);
    $etaDate = date('Y-m-d', $etaTs);

    if ($etaHours < 24) {
        $etaLabel = "Approx. {$etaHours} hrs (by " . date('D, d M H:i', $etaTs) . ")";
    } else {
        $days = (int)ceil($etaHours / 24);
        $etaLabel = "Approx. {$days} day" . ($days > 1 ? 's' : '') . " ({$etaHours} hrs · by " . date('D, d M Y', $etaTs) . ")";
    }

    $corridorNote = $isCrossBorder
        ? "AfCFTA Cross-Border Corridor ({$originHub} → {$destHub}, ~{$distanceKm} km incl. customs clearance)"
        : ($distanceKm <= 30.0
            ? "Same-City Express Zone ({$originHub} → {$destHub}, ~{$distanceKm} km)"
            : "Inter-County Linehaul ({$originHub} → {$destHub}, ~{$distanceKm} km)");

    return [
        'distance_km'   => $distanceKm,
        'eta_hours'     => $etaHours,
        'eta_datetime'  => $etaDatetime,
        'eta_date'      => $etaDate,
        'eta_label'     => $etaLabel,
        'origin_hub'    => $originHub,
        'dest_hub'      => $destHub,
        'corridor_note' => $corridorNote,
    ];
}

function ato_select_nearest_internal_carrier(PDO $pdo, array $order, array $excludeProviderIds = []): ?array {
    $st = $pdo->prepare("SELECT u.country_id, bp.city AS seller_city, c.name AS seller_country FROM users u LEFT JOIN business_profiles bp ON bp.user_id=u.id LEFT JOIN countries c ON c.id=u.country_id WHERE u.id=?");
    $st->execute([$order['seller_id']]);
    $seller = $st->fetch() ?: [];

    $sellerCoords = ato_resolve_coords(($seller['seller_city'] ?? '') . ' ' . ($seller['seller_country'] ?? ''), $seller['seller_city'] ?? null);
    $sellerCountryId = (int)($seller['country_id'] ?? 0);

    $sql = "SELECT u.id, u.full_name, u.email, u.phone, u.country_id, u.rating,
                   bp.business_name, bp.city AS carrier_city, c.name AS carrier_country,
                   (SELECT COUNT(*) FROM shipments sh WHERE sh.logistics_provider_id = u.id AND sh.status IN ('pending','collected','in_transit','at_customs','out_for_delivery')) AS active_jobs
            FROM users u
            LEFT JOIN business_profiles bp ON bp.user_id = u.id
            LEFT JOIN countries c ON c.id = u.country_id
            WHERE u.role = 'logistics'
              AND u.account_status = 'active'
              AND u.id_verification_status = 'verified'
              AND u.email_verified_at IS NOT NULL";
    $partners = $pdo->query($sql)->fetchAll();

    if (!$partners) {
        return null;
    }

    $candidates = [];
    foreach ($partners as $p) {
        if (in_array((int)$p['id'], $excludeProviderIds, true)) {
            continue;
        }
        $carrierCoords = ato_resolve_coords(($p['carrier_city'] ?? '') . ' ' . ($p['carrier_country'] ?? ''), $p['carrier_city'] ?? null);
        if ($sellerCoords && $carrierCoords) {
            $distKm = ato_haversine_km($sellerCoords['lat'], $sellerCoords['lng'], $carrierCoords['lat'], $carrierCoords['lng']);
        } elseif ($sellerCountryId > 0 && (int)$p['country_id'] === $sellerCountryId) {
            $distKm = 50.0;
        } else {
            $distKm = 800.0;
        }

        $score = $distKm + ((int)$p['active_jobs'] * 15.0) - ((float)$p['rating'] * 2.0);
        $p['proximity_km'] = $distKm;
        $p['routing_score'] = $score;
        $p['company_display_name'] = !empty($p['business_name']) ? $p['business_name'] : $p['full_name'];
        $candidates[] = $p;
    }

    if (!$candidates) {
        return null;
    }

    usort($candidates, fn($a, $b) => $a['routing_score'] <=> $b['routing_score']);
    return $candidates[0];
}
