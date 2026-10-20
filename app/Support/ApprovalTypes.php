<?php

namespace App\Support;

use App\Models\User;

/**
 * The server-owned vocabulary of approvable changes.
 *
 * Every request type names exactly three things:
 *
 *   maker     the lowest rank allowed to propose it
 *   approver  the rank that must decide it
 *   label     what the UI calls it
 *
 * A request may never name its own action. The client sends a `type` string
 * from this list, and `ApprovalService` refuses anything else - so an approved
 * request can only ever replay one of the appliers registered here, never an
 * arbitrary client-described change.
 *
 * Routing rule (from the product spec):
 *   manager requests and privileged changes -> Super Admin
 *   cashier submissions                     -> Admin (manager)
 */
class ApprovalTypes
{
    // Manager proposes, owner decides.
    public const PRODUCT_CREATE = 'product.create';

    public const PRODUCT_UPDATE = 'product.update';

    public const PRODUCT_DELETE = 'product.delete';

    public const RECIPE_UPDATE = 'recipe.update';

    public const INVENTORY_CREATE = 'inventory.create';

    public const INVENTORY_UPDATE = 'inventory.update';

    public const INVENTORY_DELETE = 'inventory.delete';

    public const SUPPLIER_CREATE = 'supplier.create';

    public const SUPPLIER_UPDATE = 'supplier.update';

    public const SUPPLIER_DELETE = 'supplier.delete';

    // Cashier proposes, manager decides.
    public const SETTLEMENT_CREATE = 'settlement.create';

    /**
     * type => [maker rank, approver rank, label, entity label key].
     *
     * `maker` is the minimum rank: a higher rank may always propose, and its own
     * proposals still need the matching approver. An owner never proposes these,
     * because an owner writes directly.
     */
    private const REGISTRY = [
        self::PRODUCT_CREATE => ['maker' => 'manager', 'approver' => 'owner', 'label' => 'New till item'],
        self::PRODUCT_UPDATE => ['maker' => 'manager', 'approver' => 'owner', 'label' => 'Price or item change'],
        self::PRODUCT_DELETE => ['maker' => 'manager', 'approver' => 'owner', 'label' => 'Remove till item'],
        self::RECIPE_UPDATE => ['maker' => 'manager', 'approver' => 'owner', 'label' => 'Recipe change'],
        self::INVENTORY_CREATE => ['maker' => 'manager', 'approver' => 'owner', 'label' => 'New stock item'],
        self::INVENTORY_UPDATE => ['maker' => 'manager', 'approver' => 'owner', 'label' => 'Stock item change'],
        self::INVENTORY_DELETE => ['maker' => 'manager', 'approver' => 'owner', 'label' => 'Remove stock item'],
        self::SUPPLIER_CREATE => ['maker' => 'manager', 'approver' => 'owner', 'label' => 'New supplier'],
        self::SUPPLIER_UPDATE => ['maker' => 'manager', 'approver' => 'owner', 'label' => 'Supplier change'],
        self::SUPPLIER_DELETE => ['maker' => 'manager', 'approver' => 'owner', 'label' => 'Remove supplier'],
        self::SETTLEMENT_CREATE => ['maker' => 'cashier', 'approver' => 'manager', 'label' => 'Debt collection'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::REGISTRY);
    }

    public static function exists(string $type): bool
    {
        return isset(self::REGISTRY[$type]);
    }

    /** @return array{maker:string,approver:string,label:string} */
    public static function definition(string $type): array
    {
        abort_unless(self::exists($type), 422, 'Unsupported approval type.');

        return self::REGISTRY[$type];
    }

    public static function label(string $type): string
    {
        return self::definition($type)['label'];
    }

    /**
     * The rank a user holds, as this registry names them.
     *
     * Employees hold no rank here: a rider or refiller cannot propose anything
     * and cannot decide anything.
     */
    public static function rankOf(User $user): ?string
    {
        return match (true) {
            $user->isSuperAdmin() => 'owner',
            $user->isAdmin() => 'manager',
            $user->role === User::ROLE_CASHIER => 'cashier',
            default => null,
        };
    }

    /** Ranks from lowest to highest, used for "at least this senior" checks. */
    private const ORDER = ['cashier' => 1, 'manager' => 2, 'owner' => 3];

    private static function atLeast(string $rank, string $required): bool
    {
        return (self::ORDER[$rank] ?? 0) >= (self::ORDER[$required] ?? PHP_INT_MAX);
    }

    /** May this user propose this type at all? */
    public static function canPropose(User $user, string $type): bool
    {
        $rank = self::rankOf($user);
        if ($rank === null) {
            return false;
        }

        return self::atLeast($rank, self::definition($type)['maker']);
    }

    /** Does this user's current rank satisfy the type's approver tier? */
    public static function canDecide(User $user, string $type): bool
    {
        $rank = self::rankOf($user);
        if ($rank === null) {
            return false;
        }

        return self::atLeast($rank, self::definition($type)['approver']);
    }

    /**
     * The type used to audit an approved change, so the audit trail reads the
     * real action rather than the generic word "approved".
     */
    public static function auditAction(string $type): string
    {
        return match ($type) {
            self::PRODUCT_CREATE, self::INVENTORY_CREATE, self::SUPPLIER_CREATE, self::SETTLEMENT_CREATE => 'create',
            self::PRODUCT_DELETE, self::INVENTORY_DELETE, self::SUPPLIER_DELETE => 'delete',
            default => 'update',
        };
    }

    /**
     * The domain model a proposal of this type edits.
     *
     * Used to decide which submitted fields are real attributes. A descriptive
     * field like `reason` must never be stored as a proposed value, or approval
     * would try to write a column that does not exist.
     */
    public static function modelFor(string $type): string
    {
        return match ($type) {
            self::PRODUCT_CREATE, self::PRODUCT_UPDATE, self::PRODUCT_DELETE, self::RECIPE_UPDATE => \App\Models\Product::class,
            self::INVENTORY_CREATE, self::INVENTORY_UPDATE, self::INVENTORY_DELETE => \App\Models\InventoryItem::class,
            self::SUPPLIER_CREATE, self::SUPPLIER_UPDATE, self::SUPPLIER_DELETE => \App\Models\Supplier::class,
            default => \App\Models\Customer::class,
        };
    }

    /**
     * Strip a submitted payload down to attributes the target model can hold.
     *
     * `Recipe` proposals keep `consumables` because that is the applier's input,
     * and `Settlement` proposals carry a fixed, applier-owned shape.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function sanitizePayload(string $type, array $payload): array
    {
        if ($type === self::RECIPE_UPDATE) {
            return array_intersect_key($payload, ['consumables' => true]);
        }

        // Creating a till item may carry an explicit recipe. `consumables` is
        // not a Product column, so the fillable filter below would drop it and
        // the applier would silently fall back to the derived recipe - a change
        // the manager did not ask for. Keep it, like a recipe edit does.
        if ($type === self::PRODUCT_CREATE) {
            return array_intersect_key($payload, array_flip(array_merge(
                (new \App\Models\Product())->getFillable(),
                ['consumables'],
            )));
        }

        if ($type === self::SETTLEMENT_CREATE) {
            return $payload;
        }

        // A stock quantity change on an EXISTING item is a stock movement with
        // its own reason and audit trail, not a silent edit. It is excluded from
        // update proposals so an approved request can never adjust stock without
        // that record.
        //
        // Creation is different: the opening quantity is a required part of the
        // new item and `createItem` writes it as an opening 'restock' movement
        // anyway, so it stays on an inventory-create proposal.
        if (in_array($type, [self::INVENTORY_UPDATE, self::INVENTORY_DELETE], true)) {
            return array_intersect_key($payload, array_flip(\App\Models\InventoryItem::PROPOSABLE_FIELDS));
        }

        if ($type === self::INVENTORY_CREATE) {
            return array_intersect_key($payload, array_flip(array_merge(
                \App\Models\InventoryItem::PROPOSABLE_FIELDS,
                ['stock_on_hand'],
            )));
        }

        /** @var \Illuminate\Database\Eloquent\Model $model */
        $model = new (self::modelFor($type))();

        return array_intersect_key($payload, array_flip($model->getFillable()));
    }
}
