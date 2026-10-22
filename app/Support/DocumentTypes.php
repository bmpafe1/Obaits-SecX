<?php
namespace App\Support;
 
class DocumentTypes
{
    // key => [label, why it exists, commonly prepared/reviewed/approved by]
    public static function all(): array
    {
        return [
            'information_security_policy' => [
                'label' => 'Information Security Policy',
                'purpose' => 'The top-level statement of how your organisation protects information.',
                'common' => [
                    'prepared_by' => 'The person responsible for IT or security',
                    'reviewed_by' => 'Compliance, risk, or a senior colleague',
                    'approved_by' => 'Managing Director or the board',
                ],
                'why' => 'Approved at the top because it commits the whole organisation.',
            ],
            'access_control_policy' => [
                'label' => 'Access Control Policy',
                'purpose' => 'Who may access which systems, and how access is granted and removed.',
                'common' => [
                    'prepared_by' => 'The person responsible for IT',
                    'reviewed_by' => 'HR',
                    'approved_by' => 'Managing Director',
                ],
                'why' => 'HR usually reviews it because it governs joiners, movers and leavers.',
            ],
            'risk_treatment_plan' => [
                'label' => 'Risk Treatment Plan',
                'purpose' => 'The risks you found, and what you have decided to do about each one.',
                'common' => [
                    'prepared_by' => 'The person responsible for security',
                    'reviewed_by' => 'Compliance or risk',
                    'approved_by' => 'Managing Director',
                ],
                'why' => 'Management approves because accepting a risk is a business decision, not a technical one.',
            ],
            'incident_response_plan' => [
                'label' => 'Incident Response Plan',
                'purpose' => 'What you do when something goes wrong.',
                'common' => [
                    'prepared_by' => 'The person responsible for IT or security',
                    'reviewed_by' => 'Operations',
                    'approved_by' => 'Managing Director',
                ],
                'why' => 'Operations reviews it because they will be part of the response.',
            ],
            'business_continuity_plan' => [
                'label' => 'Business Continuity Plan',
                'purpose' => 'How the organisation keeps running after a serious disruption.',
                'common' => [
                    'prepared_by' => 'Operations',
                    'reviewed_by' => 'The person responsible for IT',
                    'approved_by' => 'Managing Director',
                ],
                'why' => 'Continuity is a business matter with an IT dependency, so both are involved.',
            ],
            'asset_management_policy' => [
                'label' => 'Asset Management Policy',
                'purpose' => 'How you track the devices, software and data you own.',
                'common' => [
                    'prepared_by' => 'The person responsible for IT',
                    'reviewed_by' => 'Finance',
                    'approved_by' => 'Managing Director',
                ],
                'why' => 'Finance often reviews it because assets appear on the books.',
            ],
            'supplier_security_policy' => [
                'label' => 'Supplier Security Policy',
                'purpose' => 'The security you expect from vendors and service providers.',
                'common' => [
                    'prepared_by' => 'Procurement or IT',
                    'reviewed_by' => 'Legal or compliance',
                    'approved_by' => 'Managing Director',
                ],
                'why' => 'Legal usually reviews it because it becomes contractual.',
            ],
            'statement_of_applicability' => [
                'label' => 'Statement of Applicability',
                'purpose' => 'Which security controls you apply, which you do not, and why.',
                'common' => [
                    'prepared_by' => 'The person responsible for security',
                    'reviewed_by' => 'Compliance',
                    'approved_by' => 'Managing Director',
                ],
                'why' => 'It is the document an ISO 27001 auditor reads first alongside your scope.',
            ],
        ];
    }
 
    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }
 
    public const ROLES = [
        'prepared_by' => 'Prepared by',
        'reviewed_by' => 'Reviewed by',
        'approved_by' => 'Approved by',
    ];
}
