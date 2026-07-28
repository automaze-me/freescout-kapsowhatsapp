<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\KapsoWhatsApp\Http\Controllers'], function()
{
    // Webhook endpoint added in a later task.
});
