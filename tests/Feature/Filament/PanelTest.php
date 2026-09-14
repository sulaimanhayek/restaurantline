<?php

declare(strict_types=1);

use App\Filament\Resources\Conversations\ConversationResource;
use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Resources\Conversations\Pages\ViewConversation;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\MenuItems\MenuItemResource;
use App\Filament\Resources\ModifierGroups\ModifierGroupResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SmsMessages\SmsMessageResource;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\SmsMessage;
use App\Models\User;
use Filament\Resources\Resource;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| The dashboard
|--------------------------------------------------------------------------
|
| These tests are deliberately shallow and wide. A Filament panel is mostly
| configuration, and configuration fails at boot: a renamed column, a v3 method
| that no longer exists in v4, a badge closure that queries a dropped relation.
| None of that shows up in a unit test and all of it shows up as a white screen.
| So: visit every page, with real records on it, and assert it renders.
|
*/

beforeEach(function (): void {
    $this->restaurant = restaurant();

    actingAs(User::factory()->create(['restaurant_id' => $this->restaurant->id]));
});

it('sends a guest to the login page', function (): void {
    auth()->logout();

    get('/admin')->assertRedirect('/admin/login');
});

it('redirects the site root to the dashboard', function (): void {
    get('/')->assertRedirect('/admin');
});

describe('list pages', function (): void {
    it('renders with records on it', function (string $resource, callable $seed): void {
        $seed($this->restaurant);

        /** @var class-string<Filament\Resources\Resource> $resource */
        get($resource::getUrl('index'))->assertOk();
    })->with([
        'orders' => [OrderResource::class, fn (Restaurant $r) => Order::factory()->count(3)->for($r)->create()],
        'calls' => [ConversationResource::class, fn (Restaurant $r) => Conversation::factory()->count(3)->for($r)->flagged()->create()],
        'customers' => [CustomerResource::class, fn (Restaurant $r) => Customer::factory()->count(3)->for($r)->create()],
        'menu items' => [MenuItemResource::class, fn (Restaurant $r) => MenuItem::factory()->count(3)->for($r)
            ->for(MenuCategory::factory()->for($r), 'category')->create()],
        'modifier groups' => [ModifierGroupResource::class, fn (Restaurant $r) => ModifierGroup::factory()->count(3)->for($r)->create()],
        'texts' => [SmsMessageResource::class, fn (Restaurant $r) => SmsMessage::factory()->count(3)->for($r)->create()],
    ]);

    it('renders when there is nothing to show', function (string $resource): void {
        /** @var class-string<Filament\Resources\Resource> $resource */
        get($resource::getUrl('index'))->assertOk();
    })->with([
        OrderResource::class,
        ConversationResource::class,
        CustomerResource::class,
        MenuItemResource::class,
        ModifierGroupResource::class,
        SmsMessageResource::class,
    ]);
});

describe('record pages', function (): void {
    it('shows an order', function (): void {
        $order = Order::factory()->for($this->restaurant)->create();

        get(OrderResource::getUrl('view', ['record' => $order]))->assertOk();
        get(OrderResource::getUrl('edit', ['record' => $order]))->assertOk();
    });

    it('shows a customer', function (): void {
        $customer = Customer::factory()->for($this->restaurant)->create();

        get(CustomerResource::getUrl('view', ['record' => $customer]))->assertOk();
        get(CustomerResource::getUrl('edit', ['record' => $customer]))->assertOk();
    });

    it('shows a menu item', function (): void {
        $item = MenuItem::factory()
            ->for($this->restaurant)
            ->for(MenuCategory::factory()->for($this->restaurant), 'category')
            ->create();

        get(MenuItemResource::getUrl('create'))->assertOk();
        get(MenuItemResource::getUrl('edit', ['record' => $item]))->assertOk();
    });

    it('shows a modifier group', function (): void {
        $group = ModifierGroup::factory()->for($this->restaurant)->create();

        get(ModifierGroupResource::getUrl('create'))->assertOk();
        get(ModifierGroupResource::getUrl('edit', ['record' => $group]))->assertOk();
    });

    it('shows a text', function (): void {
        $message = SmsMessage::factory()->for($this->restaurant)->create();

        get(SmsMessageResource::getUrl('view', ['record' => $message]))->assertOk();
    });

    it('shows a call', function (): void {
        $conversation = Conversation::factory()->for($this->restaurant)->flagged()->create();

        get(ConversationResource::getUrl('view', ['record' => $conversation]))->assertOk();
    });
});

/*
|--------------------------------------------------------------------------
| What the dashboard refuses to do
|--------------------------------------------------------------------------
|
| Orders, calls and customers all arrive from the phone line. A "New order"
| button on this panel would produce a record with no conversation behind it,
| which is exactly the record every reconciliation question later trips over.
|
*/
it('has no create route for records that only the phone line produces', function (string $resource): void {
    /** @var class-string<Filament\Resources\Resource> $resource */
    expect($resource::canCreate())->toBeFalse()
        ->and($resource::getPages())->not->toHaveKey('create');
})->with([
    OrderResource::class,
    ConversationResource::class,
    CustomerResource::class,
    SmsMessageResource::class,
]);

it('has no way to delete a call or an order', function (): void {
    $order = Order::factory()->for($this->restaurant)->create();
    $conversation = Conversation::factory()->for($this->restaurant)->create();

    expect(OrderResource::canDelete($order))->toBeFalse()
        ->and(ConversationResource::canDelete($conversation))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Tenant scoping
|--------------------------------------------------------------------------
|
| Nothing switches tenants yet, so the only way to notice a resource that
| forgot ScopesToCurrentRestaurant is to put a second restaurant's record in
| the database and check it stays invisible. See docs/DECISIONS.md #0005.
|
*/
describe('tenant scoping', function (): void {
    it('hides another restaurant\'s records', function (string $resource, callable $seed): void {
        $other = Restaurant::factory()->create();
        $theirs = $seed($other);

        /** @var class-string<Filament\Resources\Resource> $resource */
        expect($resource::getEloquentQuery()->pluck('id'))->not->toContain($theirs->id);
    })->with([
        'orders' => [OrderResource::class, fn (Restaurant $r) => Order::factory()->for($r)->create()],
        'calls' => [ConversationResource::class, fn (Restaurant $r) => Conversation::factory()->for($r)->create()],
        'customers' => [CustomerResource::class, fn (Restaurant $r) => Customer::factory()->for($r)->create()],
        'menu items' => [MenuItemResource::class, fn (Restaurant $r) => MenuItem::factory()->for($r)
            ->for(MenuCategory::factory()->for($r), 'category')->create()],
        'modifier groups' => [ModifierGroupResource::class, fn (Restaurant $r) => ModifierGroup::factory()->for($r)->create()],
        'texts' => [SmsMessageResource::class, fn (Restaurant $r) => SmsMessage::factory()->for($r)->create()],
    ]);

    it('will not open another restaurant\'s record by id', function (): void {
        $other = Restaurant::factory()->create();
        $theirs = Order::factory()->for($other)->create();

        get(OrderResource::getUrl('view', ['record' => $theirs]))->assertNotFound();
    });
});

/*
|--------------------------------------------------------------------------
| The call review screen
|--------------------------------------------------------------------------
*/
describe('call review', function (): void {
    it('opens on the flagged calls', function (): void {
        $flagged = Conversation::factory()->for($this->restaurant)->flagged()->create();
        $fine = Conversation::factory()->for($this->restaurant)->create();

        Livewire::test(ListConversations::class)
            ->assertCanSeeTableRecords([$flagged])
            ->assertCanNotSeeTableRecords([$fine]);
    });

    it('shows every call once the filter is off', function (): void {
        $flagged = Conversation::factory()->for($this->restaurant)->flagged()->create();
        $fine = Conversation::factory()->for($this->restaurant)->create();

        Livewire::test(ListConversations::class)
            ->filterTable('needs_review', false)
            ->assertCanSeeTableRecords([$flagged, $fine]);
    });

    it('clears the flag', function (): void {
        $conversation = Conversation::factory()->for($this->restaurant)->flagged()->create();

        Livewire::test(ViewConversation::class, ['record' => $conversation->getKey()])
            ->callAction('markReviewed');

        $conversation->refresh();

        expect($conversation->needs_review)->toBeFalse()
            ->and($conversation->reviewed_at)->not->toBeNull();
    });

    it('records why a call was flagged', function (): void {
        $conversation = Conversation::factory()->for($this->restaurant)->create();

        Livewire::test(ViewConversation::class, ['record' => $conversation->getKey()])
            ->callAction('flagForReview', ['reason' => 'Read the address back wrong.']);

        $conversation->refresh();

        expect($conversation->needs_review)->toBeTrue()
            ->and($conversation->review_reason)->toBe('Read the address back wrong.');
    });

    it('insists on a reason before flagging', function (): void {
        $conversation = Conversation::factory()->for($this->restaurant)->create();

        Livewire::test(ViewConversation::class, ['record' => $conversation->getKey()])
            ->callAction('flagForReview', ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        expect($conversation->fresh()->needs_review)->toBeFalse();
    });
});
