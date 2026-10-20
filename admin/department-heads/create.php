<?php

require_once __DIR__ . '/../../includes/admin_context.php';
adminRedirect('/admin/students/create.php', ['role' => 'department_head']);
