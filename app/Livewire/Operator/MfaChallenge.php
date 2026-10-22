<?php
namespace App\Livewire\Operator;
 
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
 
#[Layout('components.layouts.operator')]
class MfaChallenge extends Component
{
    public string $code = '';
    public ?string $error = null;
    public ?string $enrolSecret = null;   // shown only during first-time enrolment
    public ?string $enrolQr = null;
 
    public function mount()
    {
        if (!session('operator_id')) { return redirect('/operator/login'); }
 
        $op = DB::table('operators')->where('id', session('operator_id'))->first();
        $g = new Google2FA();
 
        if (!$op->mfa_secret) {
            // First login — generate a secret to enrol.
            $this->enrolSecret = $g->generateSecretKey();
            $this->enrolQr = $g->getQRCodeUrl('Obaits Operator', $op->email, $this->enrolSecret);
            session(['operator_enrol_secret' => $this->enrolSecret]);
        }
    }
 
    public function verify()
    {
        $op = DB::table('operators')->where('id', session('operator_id'))->first();
        $g = new Google2FA();
 
        $secret = $op->mfa_secret ?: session('operator_enrol_secret');
        if (!$secret || !$g->verifyKey($secret, $this->code)) {
            $this->error = __('That code is not valid. Try again.');
            return;
        }
 
        // On first-time enrolment, persist the secret now that it's proven.
        if (!$op->mfa_secret) {
            DB::table('operators')->where('id', $op->id)
                ->update(['mfa_secret' => $secret, 'mfa_confirmed' => true, 'updated_at' => now()]);
            session()->forget('operator_enrol_secret');
        }
 
        session(['operator_mfa_ok' => true]);
        \App\Support\OperatorAudit::log('operator.login');
        return redirect('/operator');
    }
 
    public function render() { return view('livewire.operator.mfa')
        ->layout('components.layouts.app'); }
}
