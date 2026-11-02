<?php

/*
 * Fixed weights and periods for the data-driven evaluations. They are deliberately not editable
 * from the UI: staff cannot change how scores are calculated or overwrite the results.
 */
return [
    'product_priority' => [
        'weights' => [
            'demand' => 0.40,
            'stock' => 0.30,
            'movement' => 0.20,
            'supplier_availability' => 0.10,
        ],
        // Months of sales history summed for the Product Movement criterion.
        'movement_months' => 12,
        'classes' => [
            80 => 'Very High Priority',
            60 => 'High Priority',
            40 => 'Moderate Priority',
            20 => 'Low Priority',
            0 => 'Very Low Priority',
        ],
    ],

    'supplier' => [
        'weights' => [
            'delivery' => 0.40,
            'price' => 0.35,
            'quality' => 0.25,
        ],
        'delivery_split' => [
            'on_time' => 0.60,
            'fulfillment' => 0.40,
        ],
        // Rolling evaluation period, in months, ending today.
        'period_months' => 12,
        'classes' => [
            90 => 'Excellent',
            80 => 'Very Good',
            70 => 'Good',
            60 => 'Fair',
            0 => 'Needs Improvement',
        ],
    ],
];
