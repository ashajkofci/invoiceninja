<?php

namespace Tests\Feature\ClientPortal;

use App\Http\Controllers\ClientPortal\SwitchCompanyController;
use App\Http\Middleware\CheckClientExistence;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class SwitchCompanyTest extends TestCase
{
    use DatabaseTransactions;

    public function testContactsAreLimitedToTheCurrentCompany(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $company = Company::factory()->create(['account_id' => $account->id]);
        $other_company = Company::factory()->create(['account_id' => $account->id]);
        $email = 'portal@example.com';

        $current = $this->contact($user, $company, $email);
        $same_company = $this->contact($user, $company, $email);
        $other_company_contact = $this->contact($user, $other_company, $email);

        $this->actingAs($current, 'contact');

        $request = Request::create('/client/dashboard');
        $request->setLaravelSession(app('session')->driver());
        (new CheckClientExistence())->handle($request, fn () => response('ok'));

        $this->assertEqualsCanonicalizing(
            [$current->id, $same_company->id],
            session('multiple_contacts')->pluck('id')->all()
        );

        session()->put('multiple_contacts', collect([$current, $other_company_contact]));
        (new CheckClientExistence())->handle($request, fn () => response('ok'));
        $this->assertSame([$current->id], session('multiple_contacts')->pluck('id')->all());

        $this->expectException(ModelNotFoundException::class);
        (new SwitchCompanyController())($other_company_contact->hashed_id);
    }

    private function contact(User $user, Company $company, string $email): ClientContact
    {
        $client = Client::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        return ClientContact::factory()->create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'company_id' => $company->id,
            'email' => $email,
        ]);
    }
}
