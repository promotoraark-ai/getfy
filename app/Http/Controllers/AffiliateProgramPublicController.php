<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AffiliateEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class AffiliateProgramPublicController extends Controller
{
    public function __construct(
        private readonly AffiliateEnrollmentService $enrollment,
    ) {}

    public function show(string $slug): Response
    {
        ['program' => $program, 'product' => $product] = $this->enrollment->resolveEnabledProgram($slug);

        return Inertia::render('Afiliar/Show', [
            ...$this->enrollment->pagePayload($program, $product),
            'slug' => $slug,
            'cadastro_url' => route('afiliar.cadastro', ['slug' => $slug]),
        ]);
    }

    public function cadastro(Request $request, string $slug): Response|RedirectResponse
    {
        ['program' => $program, 'product' => $product] = $this->enrollment->resolveEnabledProgram($slug);

        $user = $request->user();
        if ($user) {
            try {
                $result = $this->enrollment->enroll($user, $program);
                Auth::login($user);
                $request->session()->regenerate();

                return redirect()->route('parceiro.dashboard')->with('success', $result['message']);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return redirect()->route('afiliar.show', ['slug' => $slug])
                    ->withErrors($e->errors());
            }
        }

        return Inertia::render('Afiliar/Cadastro', [
            ...$this->enrollment->pagePayload($program, $product),
            'slug' => $slug,
            'login_url' => route('login', ['redirect' => '/afiliar/'.$slug.'/cadastro']),
            'landing_url' => route('afiliar.show', ['slug' => $slug]),
        ]);
    }

    public function register(Request $request, string $slug): RedirectResponse
    {
        ['program' => $program, 'product' => $product] = $this->enrollment->resolveEnabledProgram($slug);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $tenantId = (int) $product->tenant_id;
        $email = strtolower(trim($validated['email']));

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user) {
            if ($user->isAdmin() || $user->isInfoprodutor() || $user->isTeam()) {
                return back()->withErrors(['email' => 'Este e-mail já possui conta de produtor/equipe. Use outro e-mail.']);
            }

            if ($user->isAfiliado() && (int) $user->tenant_id !== $tenantId) {
                return back()->withErrors(['email' => 'E-mail já vinculado a outro produtor.']);
            }

            $user->update([
                'name' => $validated['name'],
                'password' => Hash::make($validated['password']),
                'role' => User::ROLE_AFILIADO,
                'tenant_id' => $tenantId,
            ]);
        } else {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => User::ROLE_AFILIADO,
                'tenant_id' => $tenantId,
            ]);
        }

        $result = $this->enrollment->enroll($user->fresh(), $program, $validated['name']);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('parceiro.dashboard')->with('success', $result['message']);
    }
}
