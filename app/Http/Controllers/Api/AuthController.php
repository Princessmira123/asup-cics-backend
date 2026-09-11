<?php
// app/Http/Controllers/Api/AuthController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\AdminUser;
use App\Models\OtpCode;
use App\Services\FraudDetectionService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    protected $fraudService;
    protected $notifService;

    public function __construct(FraudDetectionService $fraudService, NotificationService $notifService)
    {
        $this->fraudService  = $fraudService;
        $this->notifService  = $notifService;
    }

    // ── REGISTER ──────────────────────────────────────────────────────────────
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name'    => 'required|string|max:100',
            'staff_id'     => 'required|string|unique:members,staff_id',
            'email'        => 'required|email|unique:members,email',
            'phone_number' => 'required|string|max:15',
            'password'     => 'required|string|min:8|confirmed',
            'nin'          => 'required|string|size:11',
            'date_of_birth'=> 'required|date',
            'address'      => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $member = Member::create([
            'member_id'      => 'MBR-' . date('Y') . '-' . strtoupper(Str::random(6)),
            'full_name'      => $request->full_name,
            'staff_id'       => $request->staff_id,
            'email'          => $request->email,
            'phone_number'   => $request->phone_number,
            'password_hash'  => Hash::make($request->password),
            'nin'            => $request->nin,
            'date_of_birth'  => $request->date_of_birth,
            'address'        => $request->address,
            'account_number' => $this->generateAccountNumber(),
            'status'         => 'pending_verification',
        ]);

        // Send OTP for email verification
        $otp = $this->generateOtp($member->id, 'email_verification');
        $this->notifService->sendSms($member->phone_number, "Your ASUP CICS verification OTP is: {$otp}. Valid for 10 minutes.");

        return response()->json([
            'success' => true,
            'message' => 'Registration successful. Check your phone for OTP.',
            'member_id' => $member->member_id,
        ], 201);
    }

    // ── LOGIN ─────────────────────────────────────────────────────────────────
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'          => 'required|string',
            'password'       => 'required|string',
            'device_id'      => 'nullable|string',
            'device_name'    => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $member = Member::where('email', $request->email)
                        ->orWhere('staff_id', $request->email)
                        ->first();

        if (!$member || !Hash::check($request->password, $member->password_hash)) {
            return response()->json(['success' => false, 'message' => 'Invalid credentials'], 401);
        }

        if ($member->status === 'suspended') {
            return response()->json(['success' => false, 'message' => 'Account suspended. Contact admin.'], 403);
        }

        // Check device fingerprint for fraud detection
        $deviceRisk = $this->fraudService->assessDeviceRisk($member->id, $request->device_id);

        $token = $member->createToken('member-token', ['member'])->plainTextToken;
        $member->update(['last_login' => now()]);

        return response()->json([
            'success'       => true,
            'message'       => 'Login successful',
            'token'         => $token,
            'requires_pin'  => true,
            'device_risk'   => $deviceRisk,
            'member'        => [
                'id'             => $member->member_id,
                'name'           => $member->full_name,
                'staff_id'       => $member->staff_id,
                'email'          => $member->email,
                'phone'          => $member->phone_number,
                'account_number' => $member->account_number,
                'status'         => $member->status,
                'avatar'         => strtoupper(substr($member->full_name, 0, 1) . substr(strrchr($member->full_name, ' '), 1, 1)),
            ],
        ]);
    }

    // ── ADMIN LOGIN ───────────────────────────────────────────────────────────
    public function adminLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $admin = AdminUser::where('email', $request->email)->first();

        if (!$admin || !Hash::check($request->password, $admin->password_hash)) {
            return response()->json(['success' => false, 'message' => 'Invalid admin credentials'], 401);
        }

        $token = $admin->createToken('admin-token', ['admin'])->plainTextToken;
        $admin->update(['last_login' => now()]);

        return response()->json([
            'success' => true,
            'token'   => $token,
            'admin'   => ['id' => $admin->admin_id, 'name' => $admin->full_name, 'role' => $admin->role],
        ]);
    }

    // ── VERIFY PIN ────────────────────────────────────────────────────────────
    public function verifyPin(Request $request)
    {
        $member = $request->user();

        if (!Hash::check($request->pin, $member->transaction_pin)) {
            return response()->json(['success' => false, 'message' => 'Incorrect PIN'], 401);
        }

        return response()->json(['success' => true, 'message' => 'PIN verified']);
    }

    // ── VERIFY OTP ────────────────────────────────────────────────────────────
    public function verifyOtp(Request $request)
    {
        $otp = OtpCode::where('member_id', $request->member_id)
                      ->where('code', $request->otp)
                      ->where('used', false)
                      ->where('expires_at', '>', now())
                      ->first();

        if (!$otp) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired OTP'], 400);
        }

        $otp->update(['used' => true]);
        Member::where('id', $request->member_id)->update(['status' => 'active']);

        return response()->json(['success' => true, 'message' => 'OTP verified. Account activated.']);
    }

    // ── LOGOUT ────────────────────────────────────────────────────────────────
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Logged out successfully']);
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────
    private function generateAccountNumber()
    {
        do {
            $number = '99' . str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT);
        } while (Member::where('account_number', $number)->exists());

        return $number;
    }

    private function generateOtp($memberId, $type)
    {
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        OtpCode::create([
            'member_id'  => $memberId,
            'code'       => $code,
            'type'       => $type,
            'expires_at' => now()->addMinutes(10),
        ]);
        return $code;
    }

    public function changePassword(Request $request)
    {
        $request->validate(['old_password' => 'required', 'new_password' => 'required|min:8|confirmed']);
        $member = $request->user();
        if (!Hash::check($request->old_password, $member->password_hash)) {
            return response()->json(['success' => false, 'message' => 'Old password incorrect'], 401);
        }
        $member->update(['password_hash' => Hash::make($request->new_password)]);
        return response()->json(['success' => true, 'message' => 'Password changed successfully']);
    }

    public function changePin(Request $request)
    {
        $request->validate(['old_pin' => 'required|digits:4', 'new_pin' => 'required|digits:4|confirmed']);
        $member = $request->user();
        if (!Hash::check($request->old_pin, $member->transaction_pin)) {
            return response()->json(['success' => false, 'message' => 'Old PIN incorrect'], 401);
        }
        $member->update(['transaction_pin' => Hash::make($request->new_pin)]);
        return response()->json(['success' => true, 'message' => 'PIN changed successfully']);
    }

    // ── BIOMETRIC ENROLL ──────────────────────────────────────────────────────
    // Called ONCE, right after the member has authenticated normally (password
    // + PIN) and confirmed their fingerprint/Face ID on-device. The Flutter app
    // generates a random secret locally, stores it in the phone's secure
    // hardware keystore/keychain (never sent again in plain form after this),
    // and sends it here so we can bind it to this member + this device.
    //
    // Enrolling a new device replaces any previously enrolled device for this
    // member (matches how most banking apps behave — one biometric device at
    // a time; member can see/revoke it from Settings).
    public function enrollBiometric(Request $request)
    {
        $request->validate([
            'device_id'          => 'required|string|max:64',
            'device_name'        => 'nullable|string|max:100',
            'biometric_secret'   => 'required|string|min:32',
        ]);

        $member = $request->user();
        $member->update([
            'biometric_secret_hash'  => Hash::make($request->biometric_secret),
            'biometric_device_id'    => $request->device_id,
            'biometric_device_name'  => $request->device_name ?? 'Unknown device',
            'biometric_enrolled_at'  => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Fingerprint registered as passkey for this device.',
        ]);
    }

    // ── BIOMETRIC LOGIN (passwordless) ───────────────────────────────────────
    // Public route. Called after local_auth has already confirmed the
    // member's fingerprint/Face ID on-device, which unlocked the secret from
    // secure storage. We verify that secret against the hash bound to this
    // device_id and, if it matches, issue a real session token — no password
    // needed. If someone wipes/changes device or the secret doesn't match,
    // this fails and the app falls back to password login.
    public function biometricLogin(Request $request)
    {
        $request->validate([
            'device_id'        => 'required|string',
            'biometric_secret' => 'required|string',
        ]);

        $member = Member::where('biometric_device_id', $request->device_id)->first();

        if (!$member || !$member->biometric_secret_hash ||
            !Hash::check($request->biometric_secret, $member->biometric_secret_hash)) {
            return response()->json(['success' => false, 'message' => 'Biometric login not recognized for this device. Please log in with your password.'], 401);
        }

        if ($member->status === 'suspended') {
            return response()->json(['success' => false, 'message' => 'Account suspended. Contact admin.'], 403);
        }

        $token = $member->createToken('member-token-biometric', ['member'])->plainTextToken;
        $member->update(['last_login' => now()]);

        return response()->json([
            'success'      => true,
            'message'      => 'Biometric login successful',
            'token'        => $token,
            'requires_pin' => false,
            'member'       => [
                'id'             => $member->member_id,
                'name'           => $member->full_name,
                'staff_id'       => $member->staff_id,
                'email'          => $member->email,
                'phone'          => $member->phone_number,
                'account_number' => $member->account_number,
                'status'         => $member->status,
                'avatar'         => strtoupper(substr($member->full_name, 0, 1) . substr(strrchr($member->full_name, ' '), 1, 1)),
            ],
        ]);
    }

    // ── BIOMETRIC STATUS ──────────────────────────────────────────────────────
    // Lets the app ask "is biometric login already enrolled for THIS device?"
    // so it can show the right button (Enable vs already-enabled) in Settings.
    public function biometricStatus(Request $request)
    {
        $member = $request->user();
        $deviceId = $request->query('device_id');

        return response()->json([
            'success'  => true,
            'enrolled' => !empty($member->biometric_secret_hash),
            'this_device_enrolled' => $member->biometric_device_id && $member->biometric_device_id === $deviceId,
            'device_name' => $member->biometric_device_name,
            'enrolled_at' => $member->biometric_enrolled_at,
        ]);
    }

    // ── BIOMETRIC DISABLE ──────────────────────────────────────────────────────
    public function disableBiometric(Request $request)
    {
        $request->user()->update([
            'biometric_secret_hash' => null,
            'biometric_device_id'   => null,
            'biometric_device_name' => null,
            'biometric_enrolled_at' => null,
        ]);
        return response()->json(['success' => true, 'message' => 'Fingerprint login disabled for all devices.']);
    }

    public function forgotPassword(Request $request)
    {
        $member = Member::where('email', $request->email)->first();
        if ($member) {
            $otp = $this->generateOtp($member->id, 'password_reset');
            $this->notifService->sendSms($member->phone_number, "Password reset OTP: {$otp}. Valid 10 minutes.");
        }
        return response()->json(['success' => true, 'message' => 'If account exists, OTP has been sent.']);
    }

    public function resetPassword(Request $request)
    {
        $request->validate(['email' => 'required|email', 'otp' => 'required', 'password' => 'required|min:8|confirmed']);
        $member = Member::where('email', $request->email)->first();
        if (!$member) return response()->json(['success' => false, 'message' => 'Account not found'], 404);
        $otp = OtpCode::where('member_id', $member->id)->where('code', $request->otp)->where('used', false)->where('expires_at', '>', now())->first();
        if (!$otp) return response()->json(['success' => false, 'message' => 'Invalid or expired OTP'], 400);
        $otp->update(['used' => true]);
        $member->update(['password_hash' => Hash::make($request->password)]);
        return response()->json(['success' => true, 'message' => 'Password reset successful']);
    }

    public function refreshToken(Request $request)
    {
        $member = $request->user();
        $request->user()->currentAccessToken()->delete();
        $token = $member->createToken('member-token', ['member'])->plainTextToken;
        return response()->json(['success' => true, 'token' => $token]);
    }
}
