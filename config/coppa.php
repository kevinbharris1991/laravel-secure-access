<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Age
    |--------------------------------------------------------------------------
    |
    | People younger than this age (in whole years, based on the date of birth
    | entered on the registration form) cannot register. Nothing they entered
    | is stored when the age check fails. The Children's Online Privacy
    | Protection Act (COPPA) applies to children under 13.
    |
    */

    'minimum_age' => (int) env('COPPA_MINIMUM_AGE', 13),

];
