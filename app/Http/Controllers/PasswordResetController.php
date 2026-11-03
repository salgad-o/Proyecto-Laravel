<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\SendPasswordResetLinkRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * Mensaje único: no revela si el correo existe o no.
     */
    private const MENSAJE_ENVIADO = 'Si el correo está registrado, te enviamos un enlace para restablecer la contraseña.';

    public function showRequest()
    {
        return view('pages.password.forgot');
    }

    public function send(SendPasswordResetLinkRequest $request)
    {
        Password::sendResetLink([
            'email' => $request->validated('email'),
        ]);

        return back()->with('status', self::MENSAJE_ENVIADO);
    }

    public function showReset(Request $request, string $token)
    {
        return view('pages.password.reset', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function reset(ResetPasswordRequest $request)
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Si restablecen por robo de cuenta, cerramos las sesiones abiertas.
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->getKey())
                    ->delete();

                // Solo se audita la acción: nunca la contraseña ni su hash.
                AuditLog::registrar('cambiar_password', $user, null, null);
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()
                ->route('login')
                ->with('status', 'Contraseña actualizada. Ya puedes iniciar sesión.');
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'El enlace es inválido o ha vencido.']);
    }
}
