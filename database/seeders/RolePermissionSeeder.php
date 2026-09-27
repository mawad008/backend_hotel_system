<?php

namespace Database\Seeders;

use App\Domain\IdentityAccess\Models\Permission;
use App\Domain\IdentityAccess\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the fixed Phase 1 RBAC foundation: the four approved system roles
 * (Group Owner, Hotel Manager, Reception, Guest) and the permission catalog
 * technically necessary for the Identity & Access / Hotel Group / Hotels
 * foundation, mapped per the approved Phase 0 matrix (§7), plus the
 * `roles.manage` permission added for dynamic role management.
 *
 * Permissions stay a fixed, code-controlled catalog — each slug corresponds
 * to a real Gate/policy check baked into the code — but now carry bilingual
 * (EN/AR) display metadata. Roles are dynamically manageable (create/update/
 * delete via the API); the four seeded here are marked `is_system` and
 * cannot be deleted.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'app-content.manage' => ['Manage guest app content', 'إدارة محتوى تطبيق النزلاء', 'Edit the guest app branding (logo, app name) and onboarding content', 'تعديل هوية تطبيق النزلاء (الشعار واسم التطبيق) ومحتوى شاشة الترحيب'],
            'hotel-groups.manage' => ['Manage hotel groups', 'إدارة مجموعات الفنادق', 'Create and update hotel groups', 'إنشاء مجموعات الفنادق وتحديثها'],
            'hotels.view' => ['View hotels', 'عرض الفنادق', 'View hotels within authorized scope', 'عرض الفنادق ضمن النطاق المصرح به'],
            'hotels.manage' => ['Manage hotels', 'إدارة الفنادق', 'Create and update hotels', 'إنشاء الفنادق وتحديثها'],
            'facilities.view' => ['View facilities', 'عرض المرافق', 'View the hotel facility catalog', 'عرض كتالوج مرافق الفندق'],
            'facilities.manage' => ['Manage facilities', 'إدارة المرافق', 'Create, update, delete, activate/deactivate hotel facilities', 'إنشاء مرافق الفندق وتحديثها وحذفها وتفعيلها/تعطيلها'],
            'locations.view' => ['View locations', 'عرض المواقع', 'View country and city master data', 'عرض بيانات الدول والمدن الأساسية'],
            'locations.manage' => ['Manage locations', 'إدارة المواقع', 'Create, update, delete, activate/deactivate countries and cities', 'إنشاء الدول والمدن وتحديثها وحذفها وتفعيلها/تعطيلها'],
            'users.view' => ['View users', 'عرض المستخدمين', 'View staff users', 'عرض مستخدمي الطاقم'],
            'users.manage' => ['Manage users', 'إدارة المستخدمين', 'Create, update, and delete staff users', 'إنشاء مستخدمي الطاقم وتحديثهم وحذفهم'],
            'roles.view' => ['View roles', 'عرض الأدوار', 'View roles', 'عرض الأدوار'],
            'roles.manage' => ['Manage roles', 'إدارة الأدوار', 'Create, update, and delete roles, and manage their permission assignments', 'إنشاء الأدوار وتحديثها وحذفها، وإدارة الصلاحيات المسندة إليها'],
            'permissions.view' => ['View permissions', 'عرض الصلاحيات', 'View permissions', 'عرض الصلاحيات'],
            'inventory.view' => ['View inventory', 'عرض المخزون', 'View room types and rooms within authorized scope', 'عرض أنواع الغرف والغرف ضمن النطاق المصرح به'],
            'inventory.manage' => ['Manage inventory', 'إدارة المخزون', 'Create, update, activate/deactivate room types and rooms', 'إنشاء أنواع الغرف والغرف وتحديثها وتفعيلها/تعطيلها'],
            'reservations.view' => ['View reservations', 'عرض الحجوزات', 'View reservations within authorized scope', 'عرض الحجوزات ضمن النطاق المصرح به'],
            'reservations.manage' => ['Manage reservations', 'إدارة الحجوزات', 'Create reservations within authorized scope', 'إنشاء الحجوزات ضمن النطاق المصرح به'],
            'reservations.assign-room' => ['Assign rooms', 'تخصيص الغرف', 'Assign or move the physical room of a reservation within authorized scope', 'تخصيص الغرفة الفعلية للحجز أو نقلها ضمن النطاق المصرح به'],
            'payments.manage' => ['Manage payments', 'إدارة المدفوعات', 'Initiate and manage reservation payments within authorized scope', 'بدء وإدارة مدفوعات الحجوزات ضمن النطاق المصرح به'],
            'payments.view' => ['View payments', 'عرض المدفوعات', 'View the staff-facing hotel payments ledger within authorized scope', 'عرض سجل مدفوعات الفندق ضمن النطاق المصرح به'],
            'identity-verification.view' => ['View identity verification', 'عرض التحقق من الهوية', 'View identity verification status within authorized scope', 'عرض حالة التحقق من الهوية ضمن النطاق المصرح به'],
            'identity-verification.submit' => ['Submit identity verification', 'إرسال التحقق من الهوية', 'Submit identity document/selfie for a reservation within authorized scope', 'إرسال وثيقة الهوية/الصورة الشخصية لحجز ضمن النطاق المصرح به'],
            'identity-verification.review' => ['Review identity verification', 'مراجعة التحقق من الهوية', 'Decide a pending identity verification manual review within authorized scope', 'البت في مراجعة يدوية معلّقة للتحقق من الهوية ضمن النطاق المصرح به'],
            'check-in.perform' => ['Perform check-in', 'تنفيذ تسجيل الوصول', 'Perform reservation check-in within authorized scope', 'تنفيذ تسجيل وصول الحجز ضمن النطاق المصرح به'],
            'digital-access.view' => ['View digital access', 'عرض الوصول الرقمي', 'View digital access status within authorized scope', 'عرض حالة الوصول الرقمي ضمن النطاق المصرح به'],
            'digital-access.revoke' => ['Revoke digital access', 'إلغاء الوصول الرقمي', 'Revoke a reservation digital access credential within authorized scope', 'إلغاء بيانات اعتماد الوصول الرقمي لحجز ضمن النطاق المصرح به'],
            'services.view' => ['View services', 'عرض الخدمات', 'View the hotel service catalog within authorized scope', 'عرض كتالوج خدمات الفندق ضمن النطاق المصرح به'],
            'services.manage' => ['Manage services', 'إدارة الخدمات', 'Create, update, activate/deactivate hotel services and categories within authorized scope', 'إنشاء خدمات الفندق وفئاتها وتحديثها وتفعيلها/تعطيلها ضمن النطاق المصرح به'],
            'service-orders.view' => ['View service orders', 'عرض طلبات الخدمة', 'View reservation service orders within authorized scope', 'عرض طلبات خدمة الحجوزات ضمن النطاق المصرح به'],
            'service-orders.manage' => ['Manage service orders', 'إدارة طلبات الخدمة', 'Record and transition reservation service orders within authorized scope', 'تسجيل طلبات خدمة الحجوزات ونقل حالتها ضمن النطاق المصرح به'],
            'folio.view' => ['View folio', 'عرض الفاتورة الجارية', 'View a reservation folio within authorized scope', 'عرض الفاتورة الجارية لحجز ضمن النطاق المصرح به'],
            'checkout.perform' => ['Perform checkout', 'تنفيذ المغادرة', 'Perform reservation checkout and final settlement within authorized scope', 'تنفيذ مغادرة الحجز والتسوية النهائية ضمن النطاق المصرح به'],
            'invoice.view' => ['View invoice', 'عرض الفاتورة', 'View a reservation invoice within authorized scope', 'عرض فاتورة حجز ضمن النطاق المصرح به'],
            'loyalty.view' => ['View loyalty', 'عرض الولاء', 'View a guest loyalty account and ledger within authorized scope', 'عرض حساب وسجل ولاء النزيل ضمن النطاق المصرح به'],
            'loyalty.manage' => ['Manage loyalty', 'إدارة الولاء', 'Accrue and redeem loyalty points against a reservation within authorized scope', 'استحقاق واسترداد نقاط الولاء مقابل حجز ضمن النطاق المصرح به'],
            'loyalty.rules.manage' => ['Manage loyalty rules', 'إدارة قواعد الولاء', 'Configure a hotel group loyalty rule', 'ضبط قاعدة الولاء الخاصة بمجموعة الفنادق'],
            'notifications.view' => ['View notifications', 'عرض الإشعارات', 'View and mark read a reservation notification feed within authorized scope', 'عرض قائمة إشعارات الحجز وتحديدها كمقروءة ضمن النطاق المصرح به'],
            'reviews.view' => ['View reviews', 'عرض التقييمات', 'View a hotel\'s guest reviews, including pending/rejected, within authorized scope', 'عرض تقييمات نزلاء الفندق، بما في ذلك المعلّقة/المرفوضة، ضمن النطاق المصرح به'],
            'reviews.moderate' => ['Moderate reviews', 'مراجعة التقييمات', 'Approve or reject a submitted guest review within authorized scope', 'الموافقة على تقييم نزيل مُرسل أو رفضه ضمن النطاق المصرح به'],
            'review-categories.manage' => ['Manage review categories', 'إدارة بنود التقييم', 'Create, edit, reorder, activate/deactivate and delete a hotel\'s review categories', 'إنشاء بنود تقييم الفندق وتعديلها وترتيبها وتفعيلها وتعطيلها وحذفها'],
            'problems.view' => ['View problem reports', 'عرض بلاغات المشاكل', 'View guest-submitted problem reports within authorized scope', 'عرض بلاغات المشاكل المُرسلة من النزلاء ضمن النطاق المصرح به'],
            'problems.manage' => ['Manage problem reports', 'إدارة بلاغات المشاكل', 'Triage and transition a guest problem report\'s status within authorized scope', 'فرز بلاغ مشكلة النزيل ونقل حالته ضمن النطاق المصرح به'],
            'guests.view' => ['View guests', 'عرض النزلاء', 'View the staff-facing guest directory and a guest\'s reservation history', 'عرض دليل النزلاء وسجل حجوزات النزيل'],
            'guests.manage' => ['Manage guests', 'إدارة النزلاء', 'Register a walk-in guest with no app account yet', 'تسجيل نزيل حضر مباشرة دون حساب في التطبيق'],
            'reports.view' => ['View reports', 'عرض التقارير', 'View occupancy, revenue and hotel comparison reports within authorized scope', 'عرض تقارير الإشغال والإيرادات ومقارنة الفنادق ضمن النطاق المصرح به'],
            'audit.view' => ['View audit log', 'عرض سجل التدقيق', 'View the audit trail of sensitive actions within authorized scope', 'عرض سجل تدقيق الإجراءات الحساسة ضمن النطاق المصرح به'],
        ];

        // A single bulk upsert instead of one firstOrCreate() round trip per
        // permission — every test in the suite reseeds this table in its
        // own transaction (see Tests\TestCase), and 30+ sequential
        // check-then-insert statements against the same small table proved
        // deadlock-prone under sustained load. One statement is also
        // atomic, so a partial seed can never happen.
        $now = now();
        Permission::query()->upsert(
            array_map(
                fn (string $slug, array $t) => [
                    'slug' => $slug,
                    'name_en' => $t[0],
                    'name_ar' => $t[1],
                    'description_en' => $t[2],
                    'description_ar' => $t[3],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                array_keys($permissions),
                array_values($permissions),
            ),
            uniqueBy: ['slug'],
            update: ['name_en', 'name_ar', 'description_en', 'description_ar', 'updated_at'],
        );

        $roles = [
            Role::GROUP_OWNER => [
                'name_en' => 'Group Owner',
                'name_ar' => 'مالك المجموعة',
                'description_en' => 'Group-wide oversight across all hotels.',
                'description_ar' => 'إشراف على مستوى المجموعة عبر جميع الفنادق.',
                'permissions' => array_keys($permissions),
            ],
            Role::HOTEL_MANAGER => [
                'name_en' => 'Hotel Manager',
                'name_ar' => 'مدير الفندق',
                'description_en' => 'Manages one or more assigned hotels.',
                'description_ar' => 'يدير فندقًا واحدًا أو أكثر من الفنادق المسندة إليه.',
                'permissions' => ['hotels.view', 'locations.view', 'inventory.view', 'inventory.manage', 'reservations.view', 'reservations.manage', 'payments.manage', 'payments.view', 'identity-verification.view', 'identity-verification.submit', 'identity-verification.review', 'check-in.perform', 'digital-access.view', 'digital-access.revoke', 'services.view', 'services.manage', 'service-orders.view', 'service-orders.manage', 'folio.view', 'checkout.perform', 'invoice.view', 'loyalty.view', 'loyalty.manage', 'notifications.view', 'reviews.view', 'reviews.moderate', 'review-categories.manage', 'problems.view', 'problems.manage', 'guests.view', 'guests.manage', 'reports.view', 'audit.view'],
            ],
            Role::RECEPTION => [
                'name_en' => 'Reception',
                'name_ar' => 'الاستقبال',
                'description_en' => 'Front-desk support/fallback for a single assigned hotel.',
                'description_ar' => 'دعم مكتب الاستقبال لفندق واحد مسند إليه.',
                // Phase 0 §7: Reception may review/decide a pending verification
                // (✅), assists with check-in processing (R7, R33), and may
                // issue/revoke digital access as "manual-assist, logged" — but
                // holds no financial capability. Phase 8: Reception may view
                // the service catalog and record/transition in-stay service
                // orders (operational, R7/R14-R19) and read a folio, but NOT
                // configure the catalog (services.manage = pricing config,
                // §32 "no financial edit"). Phase 9: Reception may perform the
                // operational one-tap checkout (R7/R14-R19 — every checkout is
                // audited) and read the resulting invoice. Phase 10: Reception
                // may view a guest's loyalty balance/history (operational) but
                // NOT accrue/redeem points (loyalty.manage has a monetary
                // effect on a booking — §32 "no financial edit"). Phase 11:
                // Reception may read a reservation's notification feed and
                // clear its unread markers (operational, R7 — no financial
                // effect). Reception may view a hotel's reviews (guests ask
                // at the desk) but NOT moderate (reputational judgment call,
                // reserved for Group Owner / Hotel Manager — same split as
                // loyalty.manage). Reception may look up the guest directory
                // (front-desk operational need — read only, guests.view), and
                // may view the hotel payments ledger (payments.view — a read,
                // not the financial-edit payments.manage). Reception may
                // register a walk-in guest's profile (guests.manage — a
                // front-desk identity record, no financial/reservation-state
                // effect) but reservation CREATION itself stays on
                // reservations.manage, which Reception does not hold — the
                // same split as every other "operational read/assist, not a
                // state-changing write" boundary above. Reception may view
                // and triage guest problem reports (problems.view/.manage —
                // routing a maintenance issue is routine front-desk work,
                // unlike reviews.moderate's reputational judgment call).
                'permissions' => ['hotels.view', 'locations.view', 'inventory.view', 'reservations.view', 'reservations.assign-room', 'identity-verification.view', 'identity-verification.submit', 'identity-verification.review', 'check-in.perform', 'digital-access.view', 'digital-access.revoke', 'services.view', 'service-orders.view', 'service-orders.manage', 'folio.view', 'checkout.perform', 'invoice.view', 'loyalty.view', 'notifications.view', 'reviews.view', 'problems.view', 'problems.manage', 'guests.view', 'guests.manage', 'payments.view'],
            ],
            Role::GUEST => [
                'name_en' => 'Guest',
                'name_ar' => 'نزيل',
                'description_en' => 'Guest-facing identity foundation. No staff capabilities.',
                'description_ar' => 'هوية خاصة بالنزلاء فقط. لا تملك أي صلاحيات إدارية في لوحة التحكم.',
                'permissions' => [],
            ],
        ];

        foreach ($roles as $slug => $definition) {
            $role = Role::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name_en' => $definition['name_en'],
                    'name_ar' => $definition['name_ar'],
                    'description_en' => $definition['description_en'],
                    'description_ar' => $definition['description_ar'],
                    'is_system' => true,
                ]
            );

            $permissionIds = Permission::query()
                ->whereIn('slug', $definition['permissions'])
                ->pluck('id');

            $role->permissions()->sync($permissionIds);
        }
    }
}
