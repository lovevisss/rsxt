<?php

test('home redirects guests to portal login', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('dashboard'));
});
