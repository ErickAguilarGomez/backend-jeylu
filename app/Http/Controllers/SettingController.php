<?php

namespace App\Http\Controllers;

use App\Repositories\SettingRepository;
use Illuminate\Http\Request;
use Exception;

class SettingController extends Controller
{
    protected $settingRepo;

    public function __construct(SettingRepository $settingRepo)
    {
        $this->settingRepo = $settingRepo;
    }

    /**
     * Public route to get dynamic WhatsApp settings
     */
    public function getWhatsappSettings()
    {
        $setting = \App\Models\SocialMediaSetting::where('type', 'whatsapp')->first();

        return response()->json([
            'success' => true,
            'whatsapp_number' => $setting ? $setting->phone : '51999999999',
            'whatsapp_message_template' => $setting ? $setting->default_message : ''
        ]);
    }

    /**
     * Admin protected route to update WhatsApp settings
     */
    public function updateWhatsappSettings(Request $request)
    {
        $validated = $request->validate([
            'whatsapp_number' => ['required', 'string', 'regex:/^[0-9]+$/'], // must be numeric string
            'whatsapp_message_template' => ['required', 'string']
        ], [
            'whatsapp_number.required' => 'El número de WhatsApp es obligatorio.',
            'whatsapp_number.regex' => 'El número de WhatsApp debe contener únicamente dígitos.',
            'whatsapp_message_template.required' => 'La plantilla de mensaje es obligatoria.'
        ]);

        try {
            \App\Models\SocialMediaSetting::updateOrCreate(
                ['type' => 'whatsapp'],
                [
                    'phone' => $validated['whatsapp_number'],
                    'default_message' => $validated['whatsapp_message_template'],
                    'active' => true,
                    'icon' => 'whatsapp',
                    'updated_by' => auth()->id()
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Configuración de WhatsApp actualizada exitosamente.'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar configuración: ' . $e->getMessage()
            ], 500);
        }
    }
}
