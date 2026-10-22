<?php

return [
    'intent' => [
        'auto_apply_high_confidence' => false,
        'rules' => [
            'transactional.buy' => [
                'intent' => 'transactional',
                'confidence' => 'high',
                'phrases' => ['خرید', 'سفارش'],
                'evidence' => 'عبارت شامل سیگنال اقدام مستقیم برای خرید یا سفارش است.',
            ],
            'transactional.price' => [
                'intent' => 'transactional',
                'confidence' => 'high',
                'phrases' => ['قیمت'],
                'evidence' => 'عبارت شامل سیگنال قیمت و نزدیک‌شدن به اقدام تجاری است.',
            ],
            'commercial.best' => [
                'intent' => 'commercial',
                'confidence' => 'high',
                'phrases' => ['بهترین'],
                'evidence' => 'عبارت شامل سیگنال بررسی و انتخاب بین گزینه‌ها است.',
            ],
            'commercial.compare' => [
                'intent' => 'commercial',
                'confidence' => 'high',
                'phrases' => ['مقایسه'],
                'evidence' => 'عبارت شامل سیگنال مقایسه پیش از تصمیم‌گیری است.',
            ],
            'informational.tutorial' => [
                'intent' => 'informational',
                'confidence' => 'high',
                'phrases' => ['آموزش'],
                'evidence' => 'عبارت به‌صراحت به یادگیری یا آموزش اشاره می‌کند.',
            ],
            'informational.question' => [
                'intent' => 'informational',
                'confidence' => 'high',
                'phrases' => ['چیست', 'چگونه'],
                'evidence' => 'عبارت دارای ساختار پرسشی اطلاعاتی است.',
            ],
            'navigational.login' => [
                'intent' => 'navigational',
                'confidence' => 'high',
                'phrases' => ['ورود به'],
                'evidence' => 'عبارت نشان می‌دهد کاربر قصد رفتن به یک مقصد مشخص را دارد.',
            ],
            'local.near_me' => [
                'intent' => 'local',
                'confidence' => 'high',
                'phrases' => ['نزدیک من'],
                'evidence' => 'عبارت دارای سیگنال صریح جستجوی محلی است.',
            ],
        ],
    ],
];
