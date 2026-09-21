<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Support\ClientStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Clients. Contact people arrive as a plain array and are written through the encrypted cast,
 * so the ciphertext never has to be handled by a caller.
 *
 * There is no delete: a client that is finished with is deactivated, because their projects,
 * invoices and audit trail must keep resolving.
 */
class ClientService
{
    /** The client fields a caller may write; `contacts` maps to the encrypted contact_info. */
    private const FIELDS = ['name', 'internal_notes', 'status'];

    public function __construct(
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     */
    public function create(User $actor, array $data): Client
    {
        if (! Gate::forUser($actor)->allows('create', Client::class)) {
            throw new AuthorizationException('You are not allowed to create clients.');
        }

        return DB::transaction(function () use ($actor, $data): Client {
            $client = new Client;
            $this->apply($client, $data);
            $client->save();

            $this->activity->record($client, 'Client created', $actor);

            return $client->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     */
    public function update(User $actor, Client $client, array $data): Client
    {
        if (! Gate::forUser($actor)->allows('update', $client)) {
            throw new AuthorizationException('You are not allowed to edit this client.');
        }

        return DB::transaction(function () use ($actor, $client, $data): Client {
            $this->apply($client, $data);

            $changed = array_keys($client->getDirty());

            if ($changed === []) {
                return $client;
            }

            $client->save();

            // contact_info is the column; callers and the UI know the field as contacts.
            $named = array_map(
                fn (string $field): string => $field === 'contact_info' ? 'contacts' : $field,
                $changed,
            );

            $this->activity->record($client, 'Client updated: '.implode(', ', $named), $actor);

            return $client->refresh();
        });
    }

    /**
     * Clients are never deleted. Deactivating keeps their projects and history resolvable.
     *
     * @throws AuthorizationException
     */
    public function deactivate(User $actor, Client $client): void
    {
        if (! Gate::forUser($actor)->allows('delete', $client)) {
            throw new AuthorizationException('You are not allowed to deactivate this client.');
        }

        if ($client->status === ClientStatus::Inactive) {
            return;
        }

        DB::transaction(function () use ($actor, $client): void {
            $client->update(['status' => ClientStatus::Inactive]);

            $this->activity->record($client, 'Client deactivated', $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apply(Client $client, array $data): void
    {
        $client->fill(array_intersect_key($data, array_flip(self::FIELDS)));

        if (array_key_exists('contacts', $data)) {
            // contact_info is not fillable on purpose: it is encrypted and only set here.
            $client->contact_info = $data['contacts'];
        }
    }
}
