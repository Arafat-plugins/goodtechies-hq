<?php

namespace App\Http\Requests\Client;

/**
 * A client is edited with the same shape it is created with: the contact people are replaced
 * wholesale rather than patched, because they live in one encrypted column.
 */
class UpdateClientRequest extends StoreClientRequest {}
