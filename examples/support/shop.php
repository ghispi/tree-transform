<?php

declare(strict_types=1);

/*
 * A small shop category tree as nested set rows, ordered by lft, as returned by
 * SELECT id, title, slug, products, lft, rgt FROM category ORDER BY lft.
 * "products" is the number of products directly in the category.
 *
 *     Shop
 *     ├─ Clothing
 *     │  ├─ Men            4 products
 *     │  │  ├─ Shirts     12 products
 *     │  │  └─ Shoes
 *     │  └─ Women
 *     │     └─ Dresses
 *     ├─ Electronics
 *     │  ├─ Phones
 *     │  │  └─ Cases      30 products
 *     │  └─ Laptops        7 products
 *     └─ Garden
 *        └─ Tools
 */
return [
    ['id' => 1,  'title' => 'Shop',        'slug' => 'shop',        'products' => 0,  'lft' => 1,  'rgt' => 26],
    ['id' => 2,  'title' => 'Clothing',    'slug' => 'clothing',    'products' => 0,  'lft' => 2,  'rgt' => 13],
    ['id' => 3,  'title' => 'Men',         'slug' => 'men',         'products' => 4,  'lft' => 3,  'rgt' => 8],
    ['id' => 4,  'title' => 'Shirts',      'slug' => 'shirts',      'products' => 12, 'lft' => 4,  'rgt' => 5],
    ['id' => 5,  'title' => 'Shoes',       'slug' => 'shoes',       'products' => 0,  'lft' => 6,  'rgt' => 7],
    ['id' => 6,  'title' => 'Women',       'slug' => 'women',       'products' => 0,  'lft' => 9,  'rgt' => 12],
    ['id' => 7,  'title' => 'Dresses',     'slug' => 'dresses',     'products' => 0,  'lft' => 10, 'rgt' => 11],
    ['id' => 8,  'title' => 'Electronics', 'slug' => 'electronics', 'products' => 0,  'lft' => 14, 'rgt' => 21],
    ['id' => 9,  'title' => 'Phones',      'slug' => 'phones',      'products' => 0,  'lft' => 15, 'rgt' => 18],
    ['id' => 10, 'title' => 'Cases',       'slug' => 'cases',       'products' => 30, 'lft' => 16, 'rgt' => 17],
    ['id' => 11, 'title' => 'Laptops',     'slug' => 'laptops',     'products' => 7,  'lft' => 19, 'rgt' => 20],
    ['id' => 12, 'title' => 'Garden',      'slug' => 'garden',      'products' => 0,  'lft' => 22, 'rgt' => 25],
    ['id' => 13, 'title' => 'Tools',       'slug' => 'tools',       'products' => 0,  'lft' => 23, 'rgt' => 24],
];
