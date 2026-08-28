# Multi-Application Examples

## Same account, different establishments

A user owns a barbershop and uses both Rasoio and Nexus.

Correct data model:

```text
users
  id: 10

establishments
  id: 100, app_id: RASOIO, user_id: 10, name: Peter Tecnet Barbearia
  id: 245, app_id: NEXUS,  user_id: 10, name: Peter Tecnet Barbearia
```

These rows may describe the same real-world company, but they are separate application resources with independent lifecycle, catalog, publication status, and application behavior.

Incorrect model:

```text
one establishment row shared by Rasoio and Nexus because user_id is the same
```

## Items

A Rasoio service item must reference the Rasoio establishment and carry Rasoio's `app_id`.

A Nexus catalog item must reference the Nexus establishment and carry Nexus' `app_id`.

Changing only `items.app_id` while keeping the other application's `entity_id` is invalid and is rejected by the API.

## Orders

An order created for a Nexus establishment may only contain:

- the Nexus application ID;
- that Nexus establishment ID;
- items belonging to that Nexus establishment;
- a collaborator belonging to that establishment.

The authenticated user being present in multiple applications does not relax these rules.
