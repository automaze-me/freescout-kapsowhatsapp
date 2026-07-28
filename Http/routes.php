<?php

// Webhook: stateless. Deliberately NOT in the 'web' group — that group applies
// VerifyCsrfToken and StartSession, and an unauthenticated Kapso POST would 419.
//
// Registered before the admin group below on purpose: Laravel matches routes
// in registration order, and the admin group's POST /kapso-whatsapp/{id}
// wildcard would otherwise swallow POST /kapso-whatsapp/webhook (matching
// "webhook" as {id}) and send it through the 'web,auth' middleware instead.
Route::group([
    'middleware' => ['bindings', \Modules\KapsoWhatsApp\Http\Middleware\KapsoSignature::class],
    'prefix'     => \Helper::getSubdirectory(),
    'namespace'  => 'Modules\KapsoWhatsApp\Http\Controllers',
], function () {
    Route::post('/kapso-whatsapp/webhook', 'WebhookController@receive')->name('kapsowhatsapp.webhook');
});

Route::group([
    'middleware' => ['web', 'auth'],
    'prefix'     => \Helper::getSubdirectory(),
    'namespace'  => 'Modules\KapsoWhatsApp\Http\Controllers',
], function () {
    Route::get('/kapso-whatsapp', 'KapsoWhatsAppController@settings')->name('kapsowhatsapp.settings');
    Route::get('/kapso-whatsapp/create', 'KapsoWhatsAppController@create')->name('kapsowhatsapp.create');
    Route::post('/kapso-whatsapp', 'KapsoWhatsAppController@store')->name('kapsowhatsapp.store');
    Route::get('/kapso-whatsapp/{id}/edit', 'KapsoWhatsAppController@edit')->name('kapsowhatsapp.edit');
    Route::post('/kapso-whatsapp/{id}', 'KapsoWhatsAppController@update')->name('kapsowhatsapp.update');
    Route::post('/kapso-whatsapp/{id}/delete', 'KapsoWhatsAppController@destroy')->name('kapsowhatsapp.destroy');
});
