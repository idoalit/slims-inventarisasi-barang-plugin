<?php
use SLiMS\DB;

class CreateInventorySupervision extends \SLiMS\Migration\Migration
{
    public function up()
    {
        $db = DB::getInstance();
        $definitions = [
            'templates' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, items LONGTEXT NOT NULL, source_id INT UNSIGNED NULL, created_by INT NOT NULL, created_at DATETIME NOT NULL",
            'schedules' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, location_id INT UNSIGNED NULL, template_id INT UNSIGNED NOT NULL, snapshot LONGTEXT NOT NULL, frequency VARCHAR(20) NOT NULL, start_date DATE NOT NULL, end_date DATE NULL, next_index INT UNSIGNED NOT NULL DEFAULT 0, active TINYINT NOT NULL DEFAULT 1, assignee_id INT NOT NULL, assignee_name VARCHAR(255) NOT NULL, replaces_id INT UNSIGNED NULL, version INT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, KEY watch_schedule_location (location_id), FOREIGN KEY (location_id) REFERENCES inventory_locations(id) ON DELETE SET NULL",
            'inspections' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, schedule_id INT UNSIGNED NULL, location_id INT UNSIGNED NULL, library_code VARCHAR(3) NOT NULL DEFAULT '', room_key INT UNSIGNED NOT NULL, kind VARCHAR(20) NOT NULL, due_date DATE NOT NULL, performed_date DATE NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', snapshot LONGTEXT NOT NULL, reason TEXT NOT NULL, parent_id INT UNSIGNED NULL, examiner_id INT NULL, examiner_name VARCHAR(255) NULL, notes TEXT NOT NULL, version INT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, finalized_at DATETIME NULL, UNIQUE KEY watch_schedule_date (schedule_id,due_date), KEY watch_period (due_date,library_code,room_key), FOREIGN KEY (location_id) REFERENCES inventory_locations(id) ON DELETE SET NULL",
            'results' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, inspection_id INT UNSIGNED NOT NULL, position INT NOT NULL, snapshot LONGTEXT NOT NULL, outcome VARCHAR(20) NOT NULL DEFAULT '', notes TEXT NOT NULL, assignee_id INT NULL, assignee_name VARCHAR(255) NULL, priority VARCHAR(10) NULL, deadline DATE NULL, UNIQUE KEY watch_result_position (inspection_id,position), FOREIGN KEY (inspection_id) REFERENCES inventory_watch_inspections(id)",
            'findings' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, inspection_id INT UNSIGNED NOT NULL, result_id INT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'open', assignee_id INT NOT NULL, assignee_name VARCHAR(255) NOT NULL, priority VARCHAR(10) NOT NULL, deadline DATE NOT NULL, version INT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, closed_at DATETIME NULL, UNIQUE KEY watch_finding_result (result_id), KEY watch_finding_status (status,deadline), FOREIGN KEY (inspection_id) REFERENCES inventory_watch_inspections(id), FOREIGN KEY (result_id) REFERENCES inventory_watch_results(id)",
            'actions' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, finding_id INT UNSIGNED NOT NULL, kind VARCHAR(20) NOT NULL, description TEXT NOT NULL, performed_date DATE NOT NULL, actor_id INT NOT NULL, actor_name VARCHAR(255) NOT NULL, cost DECIMAL(18,2) NULL, submitted_at DATETIME NULL, created_at DATETIME NOT NULL, FOREIGN KEY (finding_id) REFERENCES inventory_watch_findings(id)",
            'events' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, inspection_id INT UNSIGNED NOT NULL, finding_id INT UNSIGNED NULL, event VARCHAR(30) NOT NULL, notes TEXT NOT NULL, actor_id INT NOT NULL, actor_name VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY (inspection_id) REFERENCES inventory_watch_inspections(id)",
            'photos' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, inspection_id INT UNSIGNED NOT NULL, result_id INT UNSIGNED NULL, action_id INT UNSIGNED NULL, filename VARCHAR(68) NOT NULL, created_at DATETIME NOT NULL, KEY watch_photo_result (result_id), KEY watch_photo_action (action_id), FOREIGN KEY (inspection_id) REFERENCES inventory_watch_inspections(id), FOREIGN KEY (result_id) REFERENCES inventory_watch_results(id), FOREIGN KEY (action_id) REFERENCES inventory_watch_actions(id)",
        ];
        foreach ($definitions as $name => $definition) {
            $db->exec("CREATE TABLE IF NOT EXISTS inventory_watch_$name ($definition) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }
    public function down()
    {
        throw new \RuntimeException('Riwayat pengawasan harus dipertahankan. Rollback versi 7 tidak tersedia.');
    }
}
