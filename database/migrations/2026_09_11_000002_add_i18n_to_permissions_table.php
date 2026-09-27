<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions stay a fixed, code-controlled catalog (each slug corresponds
 * to a real Gate/policy check baked into the code), but gain rich bilingual
 * metadata so the Dashboard never has to display a raw slug as the primary
 * label. Same rename-then-backfill approach as the roles migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->renameColumn('name', 'name_en');
            $table->renameColumn('description', 'description_en');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->string('name_ar')->nullable()->after('name_en');
            $table->text('description_ar')->nullable()->after('description_en');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->text('description_en')->nullable()->change();
        });

        foreach ($this->translations() as $slug => $data) {
            DB::table('permissions')->where('slug', $slug)->update([
                'name_en' => $data['name_en'],
                'name_ar' => $data['name_ar'],
                'description_en' => $data['description_en'],
                'description_ar' => $data['description_ar'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['name_ar', 'description_ar']);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->renameColumn('name_en', 'name');
            $table->renameColumn('description_en', 'description');
        });
    }

    /**
     * @return array<string, array{name_en: string, name_ar: string, description_en: string, description_ar: string}>
     */
    private function translations(): array
    {
        return [
            'hotel-groups.manage' => ['name_en' => 'Manage hotel groups', 'name_ar' => 'إدارة مجموعات الفنادق', 'description_en' => 'Create and update hotel groups', 'description_ar' => 'إنشاء مجموعات الفنادق وتحديثها'],
            'hotels.view' => ['name_en' => 'View hotels', 'name_ar' => 'عرض الفنادق', 'description_en' => 'View hotels within authorized scope', 'description_ar' => 'عرض الفنادق ضمن النطاق المصرح به'],
            'hotels.manage' => ['name_en' => 'Manage hotels', 'name_ar' => 'إدارة الفنادق', 'description_en' => 'Create and update hotels', 'description_ar' => 'إنشاء الفنادق وتحديثها'],
            'facilities.view' => ['name_en' => 'View facilities', 'name_ar' => 'عرض المرافق', 'description_en' => 'View the hotel facility catalog', 'description_ar' => 'عرض كتالوج مرافق الفندق'],
            'facilities.manage' => ['name_en' => 'Manage facilities', 'name_ar' => 'إدارة المرافق', 'description_en' => 'Create, update, delete, activate/deactivate hotel facilities', 'description_ar' => 'إنشاء مرافق الفندق وتحديثها وحذفها وتفعيلها/تعطيلها'],
            'locations.view' => ['name_en' => 'View locations', 'name_ar' => 'عرض المواقع', 'description_en' => 'View country and city master data', 'description_ar' => 'عرض بيانات الدول والمدن الأساسية'],
            'locations.manage' => ['name_en' => 'Manage locations', 'name_ar' => 'إدارة المواقع', 'description_en' => 'Create, update, delete, activate/deactivate countries and cities', 'description_ar' => 'إنشاء الدول والمدن وتحديثها وحذفها وتفعيلها/تعطيلها'],
            'users.view' => ['name_en' => 'View users', 'name_ar' => 'عرض المستخدمين', 'description_en' => 'View staff users', 'description_ar' => 'عرض مستخدمي الطاقم'],
            'users.manage' => ['name_en' => 'Manage users', 'name_ar' => 'إدارة المستخدمين', 'description_en' => 'Create, update, and delete staff users', 'description_ar' => 'إنشاء مستخدمي الطاقم وتحديثهم وحذفهم'],
            'roles.view' => ['name_en' => 'View roles', 'name_ar' => 'عرض الأدوار', 'description_en' => 'View roles', 'description_ar' => 'عرض الأدوار'],
            'permissions.view' => ['name_en' => 'View permissions', 'name_ar' => 'عرض الصلاحيات', 'description_en' => 'View permissions', 'description_ar' => 'عرض الصلاحيات'],
            'inventory.view' => ['name_en' => 'View inventory', 'name_ar' => 'عرض المخزون', 'description_en' => 'View room types and rooms within authorized scope', 'description_ar' => 'عرض أنواع الغرف والغرف ضمن النطاق المصرح به'],
            'inventory.manage' => ['name_en' => 'Manage inventory', 'name_ar' => 'إدارة المخزون', 'description_en' => 'Create, update, activate/deactivate room types and rooms', 'description_ar' => 'إنشاء أنواع الغرف والغرف وتحديثها وتفعيلها/تعطيلها'],
            'reservations.view' => ['name_en' => 'View reservations', 'name_ar' => 'عرض الحجوزات', 'description_en' => 'View reservations within authorized scope', 'description_ar' => 'عرض الحجوزات ضمن النطاق المصرح به'],
            'reservations.manage' => ['name_en' => 'Manage reservations', 'name_ar' => 'إدارة الحجوزات', 'description_en' => 'Create reservations within authorized scope', 'description_ar' => 'إنشاء الحجوزات ضمن النطاق المصرح به'],
            'payments.manage' => ['name_en' => 'Manage payments', 'name_ar' => 'إدارة المدفوعات', 'description_en' => 'Initiate and manage reservation payments within authorized scope', 'description_ar' => 'بدء وإدارة مدفوعات الحجوزات ضمن النطاق المصرح به'],
            'identity-verification.view' => ['name_en' => 'View identity verification', 'name_ar' => 'عرض التحقق من الهوية', 'description_en' => 'View identity verification status within authorized scope', 'description_ar' => 'عرض حالة التحقق من الهوية ضمن النطاق المصرح به'],
            'identity-verification.submit' => ['name_en' => 'Submit identity verification', 'name_ar' => 'إرسال التحقق من الهوية', 'description_en' => 'Submit identity document/selfie for a reservation within authorized scope', 'description_ar' => 'إرسال وثيقة الهوية/الصورة الشخصية لحجز ضمن النطاق المصرح به'],
            'identity-verification.review' => ['name_en' => 'Review identity verification', 'name_ar' => 'مراجعة التحقق من الهوية', 'description_en' => 'Decide a pending identity verification manual review within authorized scope', 'description_ar' => 'البت في مراجعة يدوية معلّقة للتحقق من الهوية ضمن النطاق المصرح به'],
            'check-in.perform' => ['name_en' => 'Perform check-in', 'name_ar' => 'تنفيذ تسجيل الوصول', 'description_en' => 'Perform reservation check-in within authorized scope', 'description_ar' => 'تنفيذ تسجيل وصول الحجز ضمن النطاق المصرح به'],
            'digital-access.view' => ['name_en' => 'View digital access', 'name_ar' => 'عرض الوصول الرقمي', 'description_en' => 'View digital access status within authorized scope', 'description_ar' => 'عرض حالة الوصول الرقمي ضمن النطاق المصرح به'],
            'digital-access.revoke' => ['name_en' => 'Revoke digital access', 'name_ar' => 'إلغاء الوصول الرقمي', 'description_en' => 'Revoke a reservation digital access credential within authorized scope', 'description_ar' => 'إلغاء بيانات اعتماد الوصول الرقمي لحجز ضمن النطاق المصرح به'],
            'services.view' => ['name_en' => 'View services', 'name_ar' => 'عرض الخدمات', 'description_en' => 'View the hotel service catalog within authorized scope', 'description_ar' => 'عرض كتالوج خدمات الفندق ضمن النطاق المصرح به'],
            'services.manage' => ['name_en' => 'Manage services', 'name_ar' => 'إدارة الخدمات', 'description_en' => 'Create, update, activate/deactivate hotel services and categories within authorized scope', 'description_ar' => 'إنشاء خدمات الفندق وفئاتها وتحديثها وتفعيلها/تعطيلها ضمن النطاق المصرح به'],
            'service-orders.view' => ['name_en' => 'View service orders', 'name_ar' => 'عرض طلبات الخدمة', 'description_en' => 'View reservation service orders within authorized scope', 'description_ar' => 'عرض طلبات خدمة الحجوزات ضمن النطاق المصرح به'],
            'service-orders.manage' => ['name_en' => 'Manage service orders', 'name_ar' => 'إدارة طلبات الخدمة', 'description_en' => 'Record and transition reservation service orders within authorized scope', 'description_ar' => 'تسجيل طلبات خدمة الحجوزات ونقل حالتها ضمن النطاق المصرح به'],
            'folio.view' => ['name_en' => 'View folio', 'name_ar' => 'عرض الفاتورة الجارية', 'description_en' => 'View a reservation folio within authorized scope', 'description_ar' => 'عرض الفاتورة الجارية لحجز ضمن النطاق المصرح به'],
            'checkout.perform' => ['name_en' => 'Perform checkout', 'name_ar' => 'تنفيذ المغادرة', 'description_en' => 'Perform reservation checkout and final settlement within authorized scope', 'description_ar' => 'تنفيذ مغادرة الحجز والتسوية النهائية ضمن النطاق المصرح به'],
            'invoice.view' => ['name_en' => 'View invoice', 'name_ar' => 'عرض الفاتورة', 'description_en' => 'View a reservation invoice within authorized scope', 'description_ar' => 'عرض فاتورة حجز ضمن النطاق المصرح به'],
            'loyalty.view' => ['name_en' => 'View loyalty', 'name_ar' => 'عرض الولاء', 'description_en' => 'View a guest loyalty account and ledger within authorized scope', 'description_ar' => 'عرض حساب وسجل ولاء النزيل ضمن النطاق المصرح به'],
            'loyalty.manage' => ['name_en' => 'Manage loyalty', 'name_ar' => 'إدارة الولاء', 'description_en' => 'Accrue and redeem loyalty points against a reservation within authorized scope', 'description_ar' => 'استحقاق واسترداد نقاط الولاء مقابل حجز ضمن النطاق المصرح به'],
            'loyalty.rules.manage' => ['name_en' => 'Manage loyalty rules', 'name_ar' => 'إدارة قواعد الولاء', 'description_en' => 'Configure a hotel group loyalty rule', 'description_ar' => 'ضبط قاعدة الولاء الخاصة بمجموعة الفنادق'],
            'notifications.view' => ['name_en' => 'View notifications', 'name_ar' => 'عرض الإشعارات', 'description_en' => 'View and mark read a reservation notification feed within authorized scope', 'description_ar' => 'عرض قائمة إشعارات الحجز وتحديدها كمقروءة ضمن النطاق المصرح به'],
        ];
    }
};
