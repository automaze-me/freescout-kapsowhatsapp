<?php

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
