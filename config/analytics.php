<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tier 3 analytics service (ARIMA forecast engine)
    |--------------------------------------------------------------------------
    |
    | Laravel owns the database; the Python service owns the statistics. This
    | is the only place that talks across that boundary.
    |
    */

    'url' => rtrim((string) env('ANALYTICS_URL', 'http://127.0.0.1:5000'), '/'),

    'timeout' => (int) env('ANALYTICS_TIMEOUT', 240),

    'connect_timeout' => (int) env('ANALYTICS_CONNECT_TIMEOUT', 5),

    'history_days' => (int) env('ANALYTICS_HISTORY_DAYS', 90),

    'horizon_days' => (int) env('ANALYTICS_HORIZON_DAYS', 7),

];
