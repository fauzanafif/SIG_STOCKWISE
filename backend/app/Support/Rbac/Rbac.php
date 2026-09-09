<?php

namespace App\Support\Rbac;

/**
 * Single source of truth for RBAC (docs/roles-permissions.md).
 * Consumed by RoleSeeder / PermissionSeeder and by the `permission` middleware
 * (to reject unknown slugs early).
 */
final class Rbac
{
    /** roleSlug => [name, description] */
    public const ROLES = [
        'super_admin' => ['Super Admin', 'Akses penuh: user, role, permission, master data, settings, audit log.'],
        'admin_gudang' => ['Admin Gudang', 'Review request, inventory, stock opname (validasi & approve), NPBG, PPB, laporan.'],
        'anak_gudang' => ['Anak Gudang / Stock Opname', 'Jadwal & input stok fisik, riwayat opname.'],
        'lapangan_gudang' => ['Lapangan Gudang', 'Siapkan barang, NPBG prepare→ready, verifikasi pickup.'],
        'purchasing' => ['Purchasing', 'PPB review, vendor, RFQ, PO, receiving, monitoring lead time.'],
        'bos' => ['BOS / Management', 'Executive dashboard & seluruh laporan — read-only.'],
        'karyawan' => ['Karyawan / Requester', 'Buat & lacak request sendiri, lihat NPBG sendiri, notifikasi.'],
    ];

    /** group => [slug => human name] */
    public const PERMISSIONS = [
        'profile' => [
            'profile.view_own' => 'Lihat profil sendiri',
            'profile.update_own' => 'Ubah profil sendiri',
            'notification.view_own' => 'Lihat notifikasi sendiri',
        ],
        'user_management' => [
            'user.view' => 'Lihat user',
            'user.create' => 'Buat user',
            'user.update' => 'Ubah user',
            'user.delete' => 'Hapus user',
            'user.assign_role' => 'Assign role ke user',
            'role.view' => 'Lihat role',
            'role.manage' => 'Kelola role',
            'permission.view' => 'Lihat permission',
            'permission.manage' => 'Kelola permission',
        ],
        'master_data' => [
            'master.category.view' => 'Lihat kategori',
            'master.category.manage' => 'Kelola kategori',
            'master.unit.view' => 'Lihat satuan',
            'master.unit.manage' => 'Kelola satuan',
            'master.warehouse.view' => 'Lihat gudang',
            'master.warehouse.manage' => 'Kelola gudang',
            'master.location.view' => 'Lihat rak',
            'master.location.manage' => 'Kelola rak',
            'master.site.view' => 'Lihat site',
            'master.site.manage' => 'Kelola site',
            'master.vendor.view' => 'Lihat vendor',
            'master.vendor.manage' => 'Kelola vendor',
            'master.customer.view' => 'Lihat pelanggan',
            'master.customer.manage' => 'Kelola pelanggan',
            'master.project.view' => 'Lihat proyek',
            'master.project.manage' => 'Kelola proyek',
            'master.workshop.view' => 'Lihat bengkel',
            'master.workshop.manage' => 'Kelola bengkel',
            'master.department.view' => 'Lihat divisi',
            'master.department.manage' => 'Kelola divisi',
            'master.employee.view' => 'Lihat karyawan (roster)',
            'master.employee.manage' => 'Kelola karyawan (roster)',
        ],
        'item_inventory' => [
            'item.view' => 'Lihat barang',
            'item.create' => 'Buat barang',
            'item.update' => 'Ubah barang',
            'item.delete' => 'Hapus barang',
            'item.import' => 'Import barang dari Excel',
            'item.safety_stock.view' => 'Lihat safety stock',
            'item.safety_stock.update' => 'Ubah safety stock',
            'item.safety_stock.resolve_conflict' => 'Selesaikan konflik safety stock',
            'item.lead_time.update' => 'Ubah lead time',
            'item.alias.view' => 'Lihat alias barang',
            'item.alias.match' => 'Cocokkan barang (alias)',
            'inventory.view' => 'Lihat inventory',
            'inventory.view_analysis' => 'Lihat analisis (selisih/priority/rekomendasi)',
            'inventory.transfer' => 'Transfer stok antar gudang',
            'stock_movement.view' => 'Lihat stock movement',
        ],
        'request' => [
            'request.create' => 'Buat request',
            'request.view_own' => 'Lihat request sendiri',
            'request.update_own' => 'Ubah request sendiri (draft)',
            'request.cancel_own' => 'Batalkan request sendiri',
            'request.view' => 'Lihat semua request',
            'request.review' => 'Review request',
            'request.physical_check' => 'Cek fisik barang saat review',
            'request.reserve' => 'Reserve stok untuk request',
            'request.set_need_purchase' => 'Tandai request perlu pembelian',
            'request.cancel_any' => 'Batalkan request siapa pun',
        ],
        'npbg' => [
            'npbg.view' => 'Lihat semua NPBG',
            'npbg.view_own' => 'Lihat NPBG sendiri',
            'npbg.create' => 'Buat NPBG',
            'npbg.update' => 'Ubah NPBG (draft)',
            'npbg.cancel' => 'Batalkan NPBG',
            'npbg.prepare' => 'Siapkan barang NPBG',
            'npbg.ready' => 'Set NPBG ready to pickup',
            'npbg.pickup' => 'Konfirmasi pickup NPBG',
            'npbg.print' => 'Cetak / PDF NPBG',
            'npbg.export' => 'Export NPBG',
        ],
        'stock_opname' => [
            'opname.view' => 'Lihat stock opname',
            'opname.schedule' => 'Jadwalkan stock opname',
            'opname.count' => 'Input stok fisik',
            'opname.submit' => 'Submit hasil opname',
            'opname.review' => 'Review hasil opname',
            'opname.approve' => 'Approve opname (buat adjustment)',
            'opname.reject' => 'Reject opname',
            'opname.request_recount' => 'Minta hitung ulang',
        ],
        'ppb' => [
            'ppb.view' => 'Lihat semua PPB',
            'ppb.view_own' => 'Lihat PPB sendiri',
            'ppb.create' => 'Buat PPB',
            'ppb.update' => 'Ubah PPB',
            'ppb.submit' => 'Submit PPB',
            'ppb.review' => 'Review PPB',
            'ppb.approve' => 'Approve PPB',
            'ppb.reject' => 'Reject PPB',
            'ppb.amend' => 'Amend PPB',
            'ppb.close' => 'Close PPB',
        ],
        'purchasing' => [
            'rfq.view' => 'Lihat RFQ',
            'rfq.create' => 'Buat RFQ',
            'rfq.update' => 'Ubah RFQ',
            'rfq.send' => 'Kirim RFQ ke vendor',
            'rfq.select' => 'Pilih quote RFQ',
            'po.view' => 'Lihat PO',
            'po.create' => 'Buat PO',
            'po.update' => 'Ubah PO',
            'po.approve' => 'Approve PO',
            'po.send' => 'Kirim PO ke vendor',
            'po.cancel' => 'Batalkan PO',
            'receiving.view' => 'Lihat receiving (RI)',
            'receiving.create' => 'Buat RI',
            'receiving.update' => 'Ubah RI',
            'receiving.confirm' => 'Konfirmasi RI (stock in)',
            'receiving.reject' => 'Reject RI',
        ],
        'tracking' => [
            'lend.view' => 'Lihat Lend', 'lend.create' => 'Buat Lend', 'lend.update' => 'Ubah Lend', 'lend.return' => 'Proses pengembalian Lend',
            'borrow.view' => 'Lihat Borrow', 'borrow.create' => 'Buat Borrow', 'borrow.update' => 'Ubah Borrow', 'borrow.return' => 'Proses pengembalian Borrow',
            'stpp.view' => 'Lihat STPP', 'stpp.create' => 'Buat STPP', 'stpp.update' => 'Ubah STPP', 'stpp.return' => 'Proses penarikan STPP',
            'tyre.view' => 'Lihat Ban Luar', 'tyre.create' => 'Buat penggantian ban', 'tyre.update' => 'Ubah penggantian ban', 'tyre.close' => 'Tutup (RI ban lama)',
            'maintenance.view' => 'Lihat Maintenance', 'maintenance.create' => 'Buat SPK', 'maintenance.update' => 'Ubah SPK', 'maintenance.complete' => 'Selesaikan SPK',
            'manufacturing.view' => 'Lihat Manufaktur', 'manufacturing.create' => 'Buat MA/MJ', 'manufacturing.update' => 'Ubah MA/MJ', 'manufacturing.complete' => 'Selesaikan MA/MJ',
            'used_return.view' => 'Lihat Pengembalian Bekas', 'used_return.create' => 'Buat pengembalian bekas', 'used_return.update' => 'Ubah pengembalian bekas', 'used_return.close' => 'Tutup pengembalian bekas',
        ],
        'dashboard_report' => [
            'dashboard.karyawan' => 'Dashboard Karyawan',
            'dashboard.gudang' => 'Dashboard Gudang',
            'dashboard.opname' => 'Dashboard Opname',
            'dashboard.lapangan' => 'Dashboard Lapangan',
            'dashboard.purchasing' => 'Dashboard Purchasing',
            'dashboard.executive' => 'Dashboard Executive',
            'report.inventory' => 'Laporan inventory',
            'report.request' => 'Laporan request',
            'report.npbg' => 'Laporan NPBG',
            'report.ppb' => 'Laporan PPB',
            'report.opname' => 'Laporan opname',
            'report.stock_movement' => 'Laporan stock movement',
            'report.procurement' => 'Laporan procurement',
            'export.excel' => 'Export Excel',
            'export.csv' => 'Export CSV',
            'export.pdf' => 'Export PDF',
        ],
        'system' => [
            'audit_log.view' => 'Lihat audit log',
            'settings.view' => 'Lihat settings',
            'settings.update' => 'Ubah settings',
        ],
    ];

    /**
     * roleSlug => list of permission slugs, or ['*'] for all.
     * Mirrors the matrix in docs/roles-permissions.md §3.
     */
    public const MATRIX = [
        'super_admin' => ['*'],

        'karyawan' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'request.create', 'request.view_own', 'request.update_own', 'request.cancel_own',
            'npbg.view_own', 'npbg.print',
            'dashboard.karyawan',
            'export.excel', 'export.csv', 'export.pdf',
        ],

        'lapangan_gudang' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'inventory.view', 'stock_movement.view',
            'request.view',
            'npbg.view', 'npbg.view_own', 'npbg.prepare', 'npbg.ready', 'npbg.pickup', 'npbg.print', 'npbg.export',
            'lend.view', 'lend.create', 'lend.update', 'lend.return',
            'borrow.view', 'borrow.create', 'borrow.update', 'borrow.return',
            'stpp.view', 'stpp.create', 'stpp.update', 'stpp.return',
            'tyre.view', 'tyre.create', 'tyre.update', 'tyre.close',
            'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.complete',
            'manufacturing.view', 'manufacturing.create', 'manufacturing.update', 'manufacturing.complete',
            'used_return.view', 'used_return.create', 'used_return.update', 'used_return.close',
            'dashboard.gudang', 'dashboard.lapangan',
            'report.npbg', 'export.excel', 'export.csv', 'export.pdf',
        ],

        'anak_gudang' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'inventory.view', 'stock_movement.view', 'item.view', 'item.alias.view',
            'request.view',
            'opname.view', 'opname.count', 'opname.submit',
            'stpp.view',
            'dashboard.gudang', 'dashboard.opname',
            'report.opname', 'export.excel', 'export.csv', 'export.pdf',
        ],

        'admin_gudang' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'master.category.view', 'master.unit.view', 'master.warehouse.view', 'master.location.view',
            'master.site.view', 'master.vendor.view', 'master.customer.view', 'master.project.view',
            'master.workshop.view', 'master.department.view', 'master.employee.view',
            'item.view', 'item.create', 'item.update', 'item.delete', 'item.import',
            'item.safety_stock.view', 'item.safety_stock.update', 'item.safety_stock.resolve_conflict',
            'item.lead_time.update', 'item.alias.view', 'item.alias.match',
            'inventory.view', 'inventory.view_analysis', 'inventory.transfer', 'stock_movement.view',
            'request.view', 'request.review', 'request.physical_check', 'request.reserve',
            'request.set_need_purchase', 'request.cancel_any',
            'npbg.view', 'npbg.view_own', 'npbg.create', 'npbg.update', 'npbg.cancel',
            'npbg.prepare', 'npbg.ready', 'npbg.pickup', 'npbg.print', 'npbg.export',
            'opname.view', 'opname.schedule', 'opname.count', 'opname.submit',
            'opname.review', 'opname.approve', 'opname.reject', 'opname.request_recount',
            'ppb.view', 'ppb.view_own', 'ppb.create', 'ppb.update', 'ppb.submit',
            'ppb.review', 'ppb.approve', 'ppb.reject', 'ppb.amend', 'ppb.close',
            'lend.view', 'lend.create', 'lend.update', 'lend.return',
            'borrow.view', 'borrow.create', 'borrow.update', 'borrow.return',
            'stpp.view', 'stpp.create', 'stpp.update', 'stpp.return',
            'tyre.view', 'tyre.create', 'tyre.update', 'tyre.close',
            'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.complete',
            'manufacturing.view', 'manufacturing.create', 'manufacturing.update', 'manufacturing.complete',
            'used_return.view', 'used_return.create', 'used_return.update', 'used_return.close',
            'dashboard.gudang', 'dashboard.lapangan', 'dashboard.opname',
            'report.inventory', 'report.request', 'report.npbg', 'report.ppb', 'report.opname',
            'report.stock_movement', 'report.procurement',
            'export.excel', 'export.csv', 'export.pdf',
            'audit_log.view', 'settings.view',
        ],

        'purchasing' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'master.vendor.view', 'master.vendor.manage', 'master.category.view', 'master.unit.view',
            'item.view', 'item.alias.view',
            'inventory.view', 'inventory.view_analysis', 'stock_movement.view',
            'ppb.view', 'ppb.create', 'ppb.view_own', 'ppb.review', 'ppb.approve', 'ppb.reject', 'ppb.amend', 'ppb.close',
            'rfq.view', 'rfq.create', 'rfq.update', 'rfq.send', 'rfq.select',
            'po.view', 'po.create', 'po.update', 'po.approve', 'po.send', 'po.cancel',
            'receiving.view', 'receiving.create', 'receiving.update', 'receiving.confirm', 'receiving.reject',
            'dashboard.purchasing',
            'report.procurement', 'report.ppb', 'export.excel', 'export.csv', 'export.pdf',
        ],

        'bos' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'inventory.view', 'inventory.view_analysis', 'stock_movement.view', 'item.view',
            'request.view', 'npbg.view', 'ppb.view', 'po.view', 'receiving.view',
            'opname.view',
            'lend.view', 'borrow.view', 'stpp.view', 'tyre.view', 'maintenance.view',
            'manufacturing.view', 'used_return.view',
            'dashboard.executive',
            'report.inventory', 'report.request', 'report.npbg', 'report.ppb', 'report.opname',
            'report.stock_movement', 'report.procurement',
            'export.excel', 'export.csv', 'export.pdf',
            'audit_log.view',
        ],
    ];

    /** Flat list of every known permission slug. */
    public static function allPermissionSlugs(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::PERMISSIONS)));
    }

    /** Resolve a role's permission slugs, expanding the ['*'] wildcard. */
    public static function permissionsForRole(string $roleSlug): array
    {
        $entries = self::MATRIX[$roleSlug] ?? [];

        if ($entries === ['*']) {
            return self::allPermissionSlugs();
        }

        return $entries;
    }
}
