<?php

use App\Auth\CasIdentityResolver;

return [
    'base_url' => env('CAS_BASE_URL', 'https://cas.paas.zufedfc.edu.cn/cas'),
    'login_path' => env('CAS_LOGIN_PATH', '/login'),
    'validate_path' => env('CAS_VALIDATE_PATH', '/serviceValidate'),
    'logout_path' => env('CAS_LOGOUT_PATH', '/logout'),
    'service_url' => env('CAS_SERVICE_URL'),
    'post_logout_url' => env('CAS_POST_LOGOUT_URL'),
    'account_attribute' => env('CAS_ACCOUNT_ATTRIBUTE', 'user'),
    'ca_bundle' => env('CAS_CA_BUNDLE'),
    'verify_tls' => env('CAS_VERIFY_TLS', true),
    'timeout' => (int) env('CAS_TIMEOUT', 8),
    'routes_enabled' => false,
    'route_prefix' => env('CAS_ROUTE_PREFIX', 'auth/cas'),
    'identity_resolver' => CasIdentityResolver::class,
    'redirect_after_login' => env('CAS_REDIRECT_AFTER_LOGIN', '/dashboard'),
    'redirect_when_denied' => env('CAS_REDIRECT_WHEN_DENIED', '/access-denied'),
    'slo_cache_store' => env('CAS_SLO_CACHE_STORE'),
    'slo_ttl_seconds' => (int) env('CAS_SLO_TTL_SECONDS', 28800),
    'online_detection_enabled' => env('CAS_ONLINE_DETECTION_ENABLED', false),
    'online_status_detector' => env('CAS_ONLINE_STATUS_DETECTOR'),
];
