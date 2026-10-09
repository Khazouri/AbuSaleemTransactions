<?php

namespace App\Http\Requests\Role;

/**
 * Validates a role's new names. The same three fields as creating one, so it
 * inherits StoreRoleRequest's rules and messages rather than restating them.
 */
class UpdateRoleRequest extends StoreRoleRequest {}
