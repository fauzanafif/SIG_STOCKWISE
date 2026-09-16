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
        'lapangan_gudang' => ['Lapangan Gudang', 'Siapkan barang, NPBG prepare→ready, verifikasi pickup, hitung fisik stock opname.'],
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
            'item.view' => 'Lihat barang (halaman Master Barang)',
            'item.lookup' => 'Cari barang (untuk request/PPB)',
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
        // Bukti Keluar Barang — dokumen pickup-dari-request (dulu bernama "npbg";
        // di-rename supaya grup 'npbg' di bawah bisa jadi NPBG asli dari Accurate).
        'goods_issue' => [
            'goods_issue.view' => 'Lihat semua Bukti Keluar Barang',
            'goods_issue.view_own' => 'Lihat Bukti Keluar Barang sendiri',
            'goods_issue.create' => 'Buat Bukti Keluar Barang',
            'goods_issue.update' => 'Ubah Bukti Keluar Barang (draft)',
            'goods_issue.cancel' => 'Batalkan Bukti Keluar Barang',
            'goods_issue.prepare' => 'Siapkan barang',
            'goods_issue.ready' => 'Set ready to pickup',
            'goods_issue.pickup' => 'Konfirmasi pickup',
            'goods_issue.print' => 'Cetak / PDF',
            'goods_issue.export' => 'Export',
        ],
        // NPBG asli — mirror ARINV/ARINVDET dari Accurate (read-mostly, hanya field
        // kepemilikan Stockwise yang bisa diubah). Diisi lewat Sync Accurate.
        'npbg' => [
            'npbg.view' => 'Lihat NPBG',
            'npbg.update' => 'Ubah field NPBG (tipe/klasifikasi/proyek/dll)',
        ],
        // Klarifikasi/Verifikasi Barang — kasus barang beda type/spesifikasi.
        'npbg_verification' => [
            'npbg.verification.view' => 'Lihat klarifikasi/verifikasi barang NPBG',
            'npbg.verification.manage' => 'Ajukan/proses/tawarkan alternatif/eskalasi klarifikasi',
            'npbg.verification.respond' => 'Terima/tolak barang alternatif (Maintenance)',
            'npbg.verification.bos_decide' => 'Keputusan akhir BOS untuk klarifikasi',
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
        // Mirror Accurate REQUISITION/REQUISITIONDET — read-only, lihat AccurateSyncService::syncPpb().
        'ppb' => [
            'ppb.view' => 'Lihat PPB (mirror Accurate)',
        ],
        // Mirror Accurate APINV/APITMDET — read-only, lihat AccurateSyncService::syncRi().
        // Terpisah dari grup 'receiving' (alur internal DRAFT->CHECKING->CONFIRMED).
        'ri' => [
            'ri.view' => 'Lihat RI (mirror Accurate)',
        ],
        // Alur usulan pembelian internal (DRAFT->...->COMPLETED), dulu bernama "ppb"
        // sebelum nama itu dipakai untuk mirror Accurate di atas.
        'purchase_proposal' => [
            'purchase_proposal.view' => 'Lihat semua usulan pembelian',
            'purchase_proposal.view_own' => 'Lihat usulan pembelian sendiri',
            'purchase_proposal.create' => 'Buat usulan pembelian',
            'purchase_proposal.update' => 'Ubah usulan pembelian',
            'purchase_proposal.submit' => 'Submit usulan pembelian',
            'purchase_proposal.review' => 'Review usulan pembelian',
            'purchase_proposal.approve' => 'Approve usulan pembelian',
            'purchase_proposal.reject' => 'Reject usulan pembelian',
            'purchase_proposal.amend' => 'Amend usulan pembelian',
            'purchase_proposal.close' => 'Close usulan pembelian',
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
            'report.items' => 'Laporan Master Barang',
            'report.inventory' => 'Laporan inventory',
            'report.request' => 'Laporan request',
            'report.npbg' => 'Laporan NPBG',
            'report.ppb' => 'Laporan PPB',
            'report.ri' => 'Laporan RI',
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
        'accurate_sync' => [
            'sync.accurate.view' => 'Lihat status & riwayat sync Accurate',
            'sync.accurate.trigger' => 'Jalankan sync Accurate',
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
            'item.lookup', // cari/pilih barang saat buat request (bukan halaman Master Barang)
            'request.create', 'request.view_own', 'request.update_own', 'request.cancel_own',
            'goods_issue.view_own', 'goods_issue.print',
            'dashboard.karyawan',
            'export.excel', 'export.csv', 'export.pdf',
        ],

        'lapangan_gudang' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'inventory.view', 'stock_movement.view',
            'request.view',
            'goods_issue.view', 'goods_issue.view_own', 'goods_issue.prepare', 'goods_issue.ready',
            'goods_issue.pickup', 'goods_issue.print', 'goods_issue.export', 'npbg.view',
            'npbg.verification.view', 'npbg.verification.manage', 'npbg.verification.respond',
            // Stock opname: admin gudang menjadwalkan, admin lapangan yang turun hitung fisik & input hasilnya.
            'opname.view', 'opname.count', 'opname.submit',
            'lend.view', 'lend.create', 'lend.update', 'lend.return',
            'borrow.view', 'borrow.create', 'borrow.update', 'borrow.return',
            'stpp.view', 'stpp.create', 'stpp.update', 'stpp.return',
            'tyre.view', 'tyre.create', 'tyre.update', 'tyre.close',
            'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.complete',
            'manufacturing.view', 'manufacturing.create', 'manufacturing.update', 'manufacturing.complete',
            'used_return.view', 'used_return.create', 'used_return.update', 'used_return.close',
            'dashboard.gudang', 'dashboard.lapangan', 'dashboard.opname',
            'report.npbg', 'report.opname', 'export.excel', 'export.csv', 'export.pdf',
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
            'sync.accurate.view', 'sync.accurate.trigger',
            'request.view', 'request.review', 'request.physical_check', 'request.reserve',
            'request.set_need_purchase', 'request.cancel_any',
            'goods_issue.view', 'goods_issue.view_own', 'goods_issue.create', 'goods_issue.update', 'goods_issue.cancel',
            'goods_issue.prepare', 'goods_issue.ready', 'goods_issue.pickup', 'goods_issue.print', 'goods_issue.export',
            'npbg.view', 'npbg.update',
            'npbg.verification.view', 'npbg.verification.manage', 'npbg.verification.respond',
            'opname.view', 'opname.schedule', 'opname.count', 'opname.submit',
            'opname.review', 'opname.approve', 'opname.reject', 'opname.request_recount',
            'ppb.view', 'ri.view',
            'purchase_proposal.view', 'purchase_proposal.view_own', 'purchase_proposal.create', 'purchase_proposal.update', 'purchase_proposal.submit',
            'purchase_proposal.review', 'purchase_proposal.approve', 'purchase_proposal.reject', 'purchase_proposal.amend', 'purchase_proposal.close',
            'lend.view', 'lend.create', 'lend.update', 'lend.return',
            'borrow.view', 'borrow.create', 'borrow.update', 'borrow.return',
            'stpp.view', 'stpp.create', 'stpp.update', 'stpp.return',
            'tyre.view', 'tyre.create', 'tyre.update', 'tyre.close',
            'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.complete',
            'manufacturing.view', 'manufacturing.create', 'manufacturing.update', 'manufacturing.complete',
            'used_return.view', 'used_return.create', 'used_return.update', 'used_return.close',
            'dashboard.gudang', 'dashboard.lapangan', 'dashboard.opname',
            'report.items', 'report.inventory', 'report.request', 'report.npbg', 'report.ppb', 'report.ri', 'report.opname',
            'report.stock_movement', 'report.procurement',
            'export.excel', 'export.csv', 'export.pdf',
            'audit_log.view', 'settings.view',
        ],

        'purchasing' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'master.vendor.view', 'master.vendor.manage', 'master.category.view', 'master.unit.view',
            'master.warehouse.view',
            'item.view', 'item.alias.view',
            'inventory.view', 'inventory.view_analysis', 'stock_movement.view',
            'ppb.view', 'ri.view',
            'purchase_proposal.view', 'purchase_proposal.create', 'purchase_proposal.view_own', 'purchase_proposal.review', 'purchase_proposal.approve', 'purchase_proposal.reject', 'purchase_proposal.amend', 'purchase_proposal.close',
            'rfq.view', 'rfq.create', 'rfq.update', 'rfq.send', 'rfq.select',
            'po.view', 'po.create', 'po.update', 'po.approve', 'po.send', 'po.cancel',
            'receiving.view', 'receiving.create', 'receiving.update', 'receiving.confirm', 'receiving.reject',
            'dashboard.purchasing',
            'report.procurement', 'report.ppb', 'report.ri', 'export.excel', 'export.csv', 'export.pdf',
        ],

        'bos' => [
            'profile.view_own', 'profile.update_own', 'notification.view_own',
            'inventory.view', 'inventory.view_analysis', 'stock_movement.view', 'item.view',
            'request.view', 'goods_issue.view', 'npbg.view', 'npbg.verification.view', 'npbg.verification.bos_decide',
            'ppb.view', 'ri.view', 'purchase_proposal.view', 'po.view', 'receiving.view',
            'opname.view',
            'lend.view', 'borrow.view', 'stpp.view', 'tyre.view', 'maintenance.view',
            'manufacturing.view', 'used_return.view',
            'dashboard.executive',
            'report.items', 'report.inventory', 'report.request', 'report.npbg', 'report.ppb', 'report.ri', 'report.opname',
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
