<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function showRegister()
    {
        return view('pages.register');
    }

    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        $user = new User([
            'name'     => $data['name'],
            'email'    => strtolower($data['email']),
            'password' => Hash::make($data['password']),
        ]);

        // NP-43: prueba de la autorización legal. Se asigna de forma explícita
        // porque estas columnas están fuera de $fillable: un valor enviado en el
        // formulario (por ejemplo legal_version=9.9 o terms_accepted_at=2020-01-01)
        // se ignora. Un solo save() => una sola fila "crear" en la auditoría.
        $aceptadoEn = now();
        $user->terms_accepted_at = $aceptadoEn;
        $user->privacy_accepted_at = $aceptadoEn;
        $user->legal_version = config('legal.version');
        $user->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function showLogin()
    {
        return view('pages.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Una cuenta desactivada recibe el mismo error que unas credenciales incorrectas:
        // así no se revela que la contraseña era correcta ni que la cuenta existe.
        if (! Auth::attempt([...$credentials, 'is_active' => true])) {
            return back()->withErrors([
                'email' => 'Las credenciales no son válidas.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}