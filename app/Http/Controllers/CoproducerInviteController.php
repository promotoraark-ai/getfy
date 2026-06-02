<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CoproducerEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class CoproducerInviteController extends Controller
{
    public function __construct(
        private readonly CoproducerEnrollmentService $enrollment,
    ) {}

    public function show(string $token): Response
    {
        ['product' => $product, 'invite' => $invite] = $this->enrollment->resolvePendingInvite($token);

        return Inertia::render('Convite/CoproducaoShow', [
            ...$this->enrollment->pagePayload($product, $invite),
            'token' => $token,
            'cadastro_url' => route('convite.coproducao.cadastro', ['token' => $token]),
            'login_url' => route('login', ['redirect' => '/convite/co-producao/'.$token.'/cadastro']),
        ]);
    }

    public function cadastro(Request $request, string $token): Response|RedirectResponse
    {
        ['product' => $product, 'invite' => $invite] = $this->enrollment->resolvePendingInvite($token);

        $user = $request->user();
        if ($user) {
            try {
                $this->enrollment->activateInvite($invite, $user);
                Auth::login($user);
                $request->session()->regenerate();

                return redirect()->route('parceiro.dashboard')
                    ->with('success', 'Convite aceito. Bem-vindo ao painel de co-produção.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                return redirect()->route('convite.coproducao.show', ['token' => $token])
                    ->withErrors($e->errors());
            }
        }

        return Inertia::render('Convite/CoproducaoCadastro', [
            ...$this->enrollment->pagePayload($product, $invite),
            'token' => $token,
            'login_url' => route('login', ['redirect' => '/convite/co-producao/'.$token.'/cadastro']),
            'landing_url' => route('convite.coproducao.show', ['token' => $token]),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        ['product' => $product, 'invite' => $invite] = $this->enrollment->resolvePendingInvite($token);

        $user = $request->user();
        if ($user) {
            $this->enrollment->activateInvite($invite, $user);
            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->route('parceiro.dashboard')
                ->with('success', 'Convite aceito. Bem-vindo ao painel de co-produção.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (strtolower($validated['email']) !== strtolower($invite->email)) {
            return back()->withErrors(['email' => 'Use o mesmo e-mail do convite.']);
        }

        $tenantId = (int) $product->tenant_id;
        $email = strtolower(trim($validated['email']));
        $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing) {
            $user = $existing;
            if (! $user->isCoprodutor() && ! $user->isInfoprodutor() && ! $user->isAdmin() && ! $user->isTeam()) {
                return back()->withErrors(['email' => 'Este e-mail já está em uso com outro perfil.']);
            }
            if ($user->isCoprodutor() && ! $user->tenant_id) {
                $user->update(['tenant_id' => $tenantId]);
            }
            $user->update([
                'name' => $validated['name'],
                'password' => Hash::make($validated['password']),
            ]);
        } else {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => User::ROLE_COPRODUTOR,
                'tenant_id' => $tenantId,
            ]);
        }

        $this->enrollment->activateInvite($invite, $user);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('parceiro.dashboard')
            ->with('success', 'Convite aceito. Bem-vindo ao painel de co-produção.');
    }
}
