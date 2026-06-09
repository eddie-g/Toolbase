<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hero Background Animation
    |--------------------------------------------------------------------------
    |
    | Controls which animated background is shown in the home page hero.
    |
    |   'space'    (Option 1) - Saturn planet with orbiting particle rings on a
    |                           dark starfield.
    |   'circuits'  (Option 2) - Particles assembling into a dotted wordmark.
    |   'fill_logo' (Option 3) - Custom outlined Netkit SVG with blue and orange
    |                            dots filling the letters.
    |
    */

    'hero_background' => env('HOME_HERO_BACKGROUND', 'fill_logo'),

];
