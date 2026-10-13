<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Hash;

class UserAdminController extends Controller
{
    public function index()
    {
        return response()->json(
            User::orderBy('name')->get(),
            200
        );
    }

    /** A szerepkörök és jogosultságaik (a felület súgójához). */
    public function roles(): JsonResponse
    {
        return response()->json(
            collect(Roles::LABELS)->map(fn($label, $key) => [
                'key' => $key,
                'label' => $label,
                'permissions' => collect(Roles::permissionsFor($key))->map(fn($p) => Roles::PERMISSIONS[$p])->values(),
            ])->values()
        );
    }

    /** Erős jelszó: legalább 12 karakter, kis- és nagybetű, szám. */
    private function passwordRule(): Password
    {
        return Password::min(12)->mixedCase()->numbers();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', $this->passwordRule()],
            'role' => ['required', Rule::in(Roles::all())],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        ActivityLogger::log(
            'user.created',
            sprintf('Felhasználó felvéve: %s (%s, %s)', $user->name, $user->email, Roles::label($user->role)),
            $user,
            ['role' => $user->role],
            subjectLabel: $user->name
        );

        return response()->json($user, 201);
    }

    public function show(User $user)
    {
        return response()->json($user, 200);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', $this->passwordRule()],
            'role' => ['sometimes', Rule::in(Roles::all())],
        ]);

        $oldRole = $user->role;
        $newRole = $validated['role'] ?? $oldRole;
        $roleChanged = $newRole !== $oldRole;

        if ($roleChanged) {
            // A szerepkör módosítása csak adminisztrátornak lehetséges (az útvonal is ezt követeli meg), a sajátját senki nem módosíthatja
            if (!$request->user()->hasPermission('users.manage')) {
                abort(response()->json(['message' => 'A szerepkör módosításához nincs jogosultságod.'], 403));
            }
            if ($request->user()->id === $user->id) {
                return response()->json(['message' => 'A saját szerepköröd nem módosítható.'], 422);
            }
            if ($oldRole === Roles::ADMIN && $this->adminCount() <= 1) {
                return response()->json(['message' => 'Az utolsó adminisztrátor szerepköre nem módosítható.'], 422);
            }
        }

        $emailChanged = $user->email !== $validated['email'];
        $passwordChanged = !empty($validated['password']);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        if ($passwordChanged) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        // Jelszó vagy e-mail változásakor minden korábbi munkamenet érvénytelen (a saját jelenlegi tokent kivéve)
        if ($passwordChanged || $emailChanged) {
            $current = $request->user()->id === $user->id ? $request->user()->currentAccessToken()?->id : null;
            $user->tokens()->when($current, fn($q) => $q->where('id', '!=', $current))->delete();

            ActivityLogger::log(
                'user.updated',
                sprintf('Felhasználó módosítva: %s%s%s', $user->name, $passwordChanged ? ' (jelszó csere)' : '', $emailChanged ? ' (e-mail csere)' : ''),
                $user,
                ['password_changed' => $passwordChanged, 'email_changed' => $emailChanged],
                subjectLabel: $user->name
            );
        }

        if ($roleChanged) {
            $user->update(['role' => $newRole]);

            // A módosított szerepkörű felhasználó újra be kell lépjen, így a felülete azonnal a friss jogokat mutatja
            $user->tokens()->delete();

            ActivityLogger::log(
                'user.role_changed',
                sprintf('Szerepkör módosítva: %s: %s → %s', $user->name, Roles::label($oldRole), Roles::label($newRole)),
                $user,
                ['old_role' => $oldRole, 'new_role' => $newRole],
                subjectLabel: $user->name
            );
        }

        return response()->json($user->fresh(), 200);
    }

    public function destroy(User $user)
    {
        if (auth()->id() === $user->id) {
            return response()->json(
                ['message' => 'Cannot delete your own account.'],
                400
            );
        }

        if ($user->role === Roles::ADMIN && $this->adminCount() <= 1) {
            return response()->json(['message' => 'Az utolsó adminisztrátor nem törölhető.'], 422);
        }

        $label = sprintf('%s (%s)', $user->name, $user->email);
        $user->tokens()->delete();
        $user->delete();

        ActivityLogger::log('user.deleted', 'Felhasználó törölve: ' . $label, null, ['email' => $user->email], subjectLabel: $label);

        return response()->json(
            ['message' => 'User deleted successfully.'],
            200
        );
    }

    private function adminCount(): int
    {
        return User::where('role', Roles::ADMIN)->count();
    }
}
