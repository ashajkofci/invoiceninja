<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Models;

use App\Jobs\Mail\NinjaMailer;
use App\Jobs\Mail\NinjaMailerJob;
use App\Jobs\Mail\NinjaMailerObject;
use App\Mail\Admin\ResetPasswordObject;
use App\Models\Presenters\UserPresenter;
use App\Notifications\ResetPasswordNotification;
use App\Services\User\UserService;
use App\Utils\Traits\MakesHash;
use App\Utils\Traits\UserSessionAttributes;
use App\Utils\Traits\UserSettings;
use App\Utils\TruthSource;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laracasts\Presenter\PresentableTrait;

class User extends Authenticatable implements MustVerifyEmail
{
    use Notifiable;
    use SoftDeletes;
    use PresentableTrait;
    use MakesHash;
    use UserSessionAttributes;
    use UserSettings;
    use Filterable;
    use HasFactory;
    use \Awobaz\Compoships\Compoships;

    protected $guard = 'user';

    protected $presenter = UserPresenter::class;

    protected $with = []; // ? companies also

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public $company;

    protected $appends = [
        'hashed_id',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'signature',
        'avatar',
        'accepted_terms_version',
        'oauth_user_id',
        'oauth_provider_id',
        'oauth_user_token',
        'oauth_user_refresh_token',
        'custom_value1',
        'custom_value2',
        'custom_value3',
        'custom_value4',
        'is_deleted',
        // 'google_2fa_secret',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'remember_token',
        'google_2fa_secret',
        'google_2fa_phone',
        'remember_2fa_token',
        'slack_webhook_url',
    ];

    protected $casts = [
        'oauth_user_token' => 'object',
        'settings'         => 'object',
        'updated_at'       => 'timestamp',
        'created_at'       => 'timestamp',
        'deleted_at'       => 'timestamp',
        'oauth_user_token_expiry' => 'datetime',
    ];

    public function name()
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function getEntityType()
    {
        return self::class;
    }

    public function getHashedIdAttribute()
    {
        return $this->encodePrimaryKey($this->id);
    }

    /**
     * Returns a account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Returns all company tokens.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function tokens()
    {
        return $this->hasMany(CompanyToken::class)->orderBy('id', 'ASC');
    }

    public function token()
    {
        $truth = app()->make(TruthSource::class);

        if ($truth->getCompanyToken()) {
            return $truth->getCompanyToken();
        }

        if (request()->header('X-API-TOKEN')) {
            return CompanyToken::with(['cu'])->where('token', request()->header('X-API-TOKEN'))->first();
        }

        return $this->tokens()->first();
    }

    /**
     * Returns all companies a user has access to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function companies()
    {
        return $this->belongsToMany(Company::class)->using(CompanyUser::class)->withPivot('permissions', 'settings', 'is_admin', 'is_owner', 'is_locked')->withTimestamps();
    }

    /**
     * As we are authenticating on CompanyToken,
     * we need to link the company to the user manually. This allows
     * us to decouple a $user and their attached companies.
     * @param $company
     */
    public function setCompany($company)
    {
        $this->company = $company;

        return $this;
    }

    /**
     * Returns the currently set Company.
     */
    public function getCompany()
    {
        $truth = app()->make(TruthSource::class);

        if ($this->company) {
            return $this->company;
        } elseif ($truth->getCompany()) {
            return $truth->getCompany();
        } elseif (request()->header('X-API-TOKEN')) {
            $company_token = CompanyToken::with(['company'])->where('token', request()->header('X-API-TOKEN'))->first();
            return $company_token->company;
        }

        throw new \Exception('No Company Found');
    }

    public function companyIsSet()
    {
        if ($this->company) {
            return true;
        }

        return false;
    }

    /**
     * Returns the current company.
     *
     * @return App\Models\Company $company
     */
    public function company()
    {
        return $this->getCompany();
    }

    private function setCompanyByGuard()
    {
        if (Auth::guard('contact')->check()) {
            $this->setCompany(auth()->user()->client->company);
        }
    }

    public function company_users()
    {
        return $this->hasMany(CompanyUser::class)->withTrashed();
    }

    public function co_user()
    {
        $truth = app()->make(TruthSource::class);

        if ($truth->getCompanyUser()) {
            return $truth->getCompanyUser();
        }

        return $this->token()->cu;
    }

    public function company_user()
    {
        if ($this->companyId()) {
            return $this->belongsTo(CompanyUser::class)->where('company_id', $this->companyId())->withTrashed();
        }

        $truth = app()->make(TruthSource::class);

        if ($truth->getCompanyUser()) {
            return $truth->getCompanyUser();
        }

        return $this->token()->cu;

        // return $this->hasOneThrough(CompanyUser::class, CompanyToken::class, 'user_id', 'user_id', 'id', 'user_id')
        // ->withTrashed();
    }

    /**
     * Returns the currently set company id for the user.
     *
     * @return int
     */
    public function companyId() :int
    {
        return $this->company()->id;
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    /**
     * Returns a comma separated list of user permissions.
     *
     * @return comma separated list
     */
    public function permissions()
    {
        return $this->token()->cu->permissions;

        // return $this->company_user->permissions;
    }

    /**
     * Returns a object of User Settings.
     *
     * @return stdClass
     */
    public function settings()
    {
        return json_decode($this->token()->cu->settings);

        //return json_decode($this->company_user->settings);
    }

    /**
     * Returns a boolean of the administrator status of the user.
     *
     * @return bool
     */
    public function isAdmin() : bool
    {
        return $this->token()->cu->is_admin;

        // return $this->company_user->is_admin;
    }

    public function isOwner() : bool
    {
        return $this->token()->cu->is_owner;

        // return $this->company_user->is_owner;
    }

    /**
     * Returns true is user is an admin _or_ owner
     *
     * @return boolean
     */
    public function isSuperUser() :bool
    {
        return $this->token()->cu->is_owner || $this->token()->cu->is_admin;
    }

    /**
     * Returns all user created contacts.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function contacts()
    {
        return $this->hasMany(ClientContact::class);
    }

    /**
     * Returns a boolean value if the user owns the current Entity.
     *
     * @param  string Entity
     * @return bool
     */
    public function owns($entity) : bool
    {
        return ! empty($entity->user_id) && $entity->user_id == $this->id;
    }

    /**
     * Returns a boolean value if the user is assigned to the current Entity.
     *
     * @param  string Entity
     * @return bool
     */
    public function assigned($entity) : bool
    {
        return ! empty($entity->assigned_user_id) && $entity->assigned_user_id == $this->id;
    }

    /**
     * Returns true if permissions exist in the map.
     *
     * @param  string permission
     * @return bool
     */
    public function hasPermission($permission) : bool
    {
        /**
         * We use the limit parameter here to ensure we don't split on permissions that have multiple underscores.
         *
         * For example view_recurring_invoice without the limit would split to view bank recurring invoice
         *
         * Using only part 0 and 1 would search for permission view_recurring / edit_recurring so this would
         * leak permissions for other recurring_* entities
         *
         * The solution here will split the word - consistently - into view _ {entity} and edit _ {entity}
         *
         */
        $parts = explode('_', $permission, 2);
        $all_permission = '____';
        $edit_all = '____';
        $edit_entity = '____';

        /* If we have multiple parts, then make sure we search for the _all permission */
        if (count($parts) > 1) {
            $all_permission = $parts[0].'_all';

            /*If this is a view search, make sure we add in the edit_{entity} AND edit_all permission into the checks*/
            if ($parts[0] == 'view') {
                $edit_all = 'edit_all';
                $edit_entity = "edit_{$parts[1]}";
            }
        }

        return  $this->isSuperUser() ||
                (stripos($this->token()->cu->permissions, $permission) !== false) ||
                (stripos($this->token()->cu->permissions, $all_permission) !== false) ||
                (stripos($this->token()->cu->permissions, $edit_all) !== false) ||
                (stripos($this->token()->cu->permissions, $edit_entity) !== false);
    }

    /**
     * Used when we need to match exactly what permission
     * the user has, and not aggregate owner and admins.
     *
     * This method is used when we need to scope down the query
     * and display a limited subset.
     *
     * @param  string  $permission '["view_all"]'
     * @return boolean
     */
    public function hasExactPermissionAndAll(string $permission = '___'): bool
    {
        $parts = explode('_', $permission);
        $all_permission = '__';

        if (count($parts) > 1) {
            $all_permission = $parts[0].'_all';
        }

        return  (stripos($this->token()->cu->permissions, $all_permission) !== false) ||
                (stripos($this->token()->cu->permissions, $permission) !== false);
    }

    /**
     * Used when we need to match a range of permissions
     * the user
     *
     * This method is used when we need to scope down the query
     * and display a limited subset.
     *
     * @param  array  $permissions
     * @return boolean
     */
    public function hasIntersectPermissions(array $permissions = []): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasExactPermissionAndAll($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Used when we need to match exactly what permission
     * the user has, and not aggregate owner and admins.
     *
     * This method is used when we need to scope down the query
     * and display a limited subset.
     *
     * @param  string  $permission '["view_all"]'
     * @return boolean
     */
    public function hasExactPermission(string $permission = '___'): bool
    {
        return  (stripos($this->token()->cu->permissions, $permission) !== false);
    }


    /**
     * Used when we need to match a range of permissions
     * the user
     *
     * This method is used when we need to scope down the query
     * and display a limited subset.
     *
     * @param  array  $permissions
     * @return boolean
     */
    public function hasIntersectPermissionsOrAdmin(array $permissions = []): bool
    {
        if ($this->isSuperUser()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($this->hasExactPermissionAndAll($permission)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Used when we need to filter permissions carefully.
     *
     * For instance, users that have view_client permissions should not
     * see the client balance, however if they also have
     * view_invoice or view_all etc, then they should be able to see the balance.
     *
     * First we pass over the excluded permissions and return false if we find a match.
     *
     * If those permissions are not hit, then we can iterate through the matched_permissions and search for a hit.
     *
     * Note, returning FALSE here means the user does NOT have the permission we want to exclude
     *
     * @param  array $matched_permission
     * @param  array $excluded_permissions
     * @return bool
     */
    public function hasExcludedPermissions(array $matched_permission = [], array $excluded_permissions = []): bool
    {
        if ($this->isSuperUser()) {
            return false;
        }
        
        foreach ($excluded_permissions as $permission) {
            if ($this->hasExactPermission($permission)) {
                return false;
            }
        }

        foreach ($matched_permission as $permission) {
            if ($this->hasExactPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isVerified()
    {
        return is_null($this->email_verified_at) ? false : true;
    }

    public function getEmailVerifiedAt()
    {
        if ($this->email_verified_at) {
            return Carbon::parse($this->email_verified_at)->timestamp;
        } else {
            return null;
        }
    }

    public function routeNotificationForSlack($notification)
    {
        if ($this->token()->cu->slack_webhook_url) {
            return $this->token()->cu->slack_webhook_url;
        }
    }

    public function routeNotificationForMail($notification)
    {
        return $this->email;
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param mixed $value
     * @param null $field
     * @return Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this
            ->withTrashed()
            ->where('id', $this->decodePrimaryKey($value))
            ->where('account_id', auth()->user()->account_id)
            ->firstOrFail();
    }

    /**
     * Send the password reset notification.
     *
     * @param  string  $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $nmo = new NinjaMailerObject;
        $nmo->mailable = new NinjaMailer((new ResetPasswordObject($token, $this, $this->account->default_company))->build());
        $nmo->to_user = $this;
        $nmo->settings = $this->account->default_company->settings;
        $nmo->company = $this->account->default_company;

        NinjaMailerJob::dispatch($nmo, true);

        //$this->notify(new ResetPasswordNotification($token));
    }

    public function service()
    {
        return new UserService($this);
    }

    public function translate_entity()
    {
        return ctrans('texts.user');
    }
}
