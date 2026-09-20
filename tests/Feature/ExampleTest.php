<?php

it('redirects a guest from the home route to the login page', function () {
    $this->get('/')->assertRedirect('/login');

    $this->assertGuest();
})->group('phase0');
