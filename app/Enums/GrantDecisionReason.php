<?php

namespace App\Enums;

enum GrantDecisionReason: string
{
    case Approved = 'approved';
    case EligibilityNotMet = 'eligibility_not_met';
    case MoreInformationRequired = 'more_information_required';
    case AlreadyReceived = 'already_received';
    case ProgramUnavailable = 'program_unavailable';
    case AccountUnavailable = 'account_unavailable';
    case PolicyRequirementsNotMet = 'policy_requirements_not_met';
    case OtherPolicyReason = 'other_policy_reason';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::EligibilityNotMet => 'Eligibility requirements not met',
            self::MoreInformationRequired => 'More information required',
            self::AlreadyReceived => 'Grant already received',
            self::ProgramUnavailable => 'Program unavailable',
            self::AccountUnavailable => 'Selected account unavailable',
            self::PolicyRequirementsNotMet => 'Policy requirements not met',
            self::OtherPolicyReason => 'Other policy reason',
        };
    }

    public function memberGuidance(): string
    {
        return match ($this) {
            self::Approved => 'Your application was approved and the recorded payout was sent to your selected account.',
            self::EligibilityNotMet => 'Review the program requirements and apply again once your nation meets them.',
            self::MoreInformationRequired => 'Update the requested information, then apply again for a new review.',
            self::AlreadyReceived => 'Our records show that this one-time grant has already been issued to your nation.',
            self::ProgramUnavailable => 'This grant program is not currently available. Check again after the program reopens.',
            self::AccountUnavailable => 'Choose an active account owned by your nation before applying again.',
            self::PolicyRequirementsNotMet => 'Review the applicable grant policy and apply again when the requirements are met.',
            self::OtherPolicyReason => 'Review the decision explanation below or contact leadership if you need clarification.',
        };
    }

    public function isDenial(): bool
    {
        return $this !== self::Approved;
    }

    /**
     * @return list<string>
     */
    public static function denialValues(): array
    {
        return array_values(array_map(
            static fn (self $reason): string => $reason->value,
            array_filter(self::cases(), static fn (self $reason): bool => $reason->isDenial()),
        ));
    }
}
