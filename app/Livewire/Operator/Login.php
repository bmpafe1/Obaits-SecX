<?php
namespace App\Livewire\Operator;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
 
#[Layout('components.layouts.operator')]
class Login extends Component
{
    public string $email = '';
    public string $password = '';
    public ?string $error = null;
 
    public function submit()
    {
        $op = DB::table('operators')->where('email', strtolower(trim($this->email)))
            ->where('active', true)->first();
 
        if (!$op || !Hash::check($this->password, $op->password)) {
            $this->error = __('Invalid credentials.');
            \App\Support\OperatorAudit::log('operator.login_failed', ['detail' => ['email' => $this->email]]);
            return;
        }
 
        // Password OK. Establish a PARTIAL session — not yet MFA-cleared.
        session([
            'operator_id'    => $op->id,
            'operator_email' => $op->email,
            'operator_mfa_ok'=> false,
        ]);
        DB::table('operators')->where('id', $op->id)->update(['last_login_at' => now()]);
 
        return redirect('/operator/mfa');
    }
 
    public function render() { return view('livewire.operator.login')
        ->layout('components.layouts.app'); }
}
