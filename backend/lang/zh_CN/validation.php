<?php

return [
    'required' => '请填写:attribute。',
    'string' => ':attribute 必须为文本。',
    'email' => '请输入有效的:attribute。',
    'date' => '请选择有效的:attribute。',
    'date_format' => ':attribute 格式不正确。',
    'file' => '请上传有效的:attribute文件。',
    'mimes' => ':attribute 必须是 :values 类型的文件。',
    'regex' => ':attribute 格式不正确。',
    'boolean' => ':attribute 必须为 true 或 false。',
    'unique' => ':attribute 已被占用。',
    'in' => ':attribute 必须是以下值之一：:values。',
    'max' => [
        'string' => ':attribute 不能超过 :max 个字符。',
        'file' => ':attribute 不能大于 :max KB。',
    ],
    'attributes' => [
        'locale' => '语言',
        'email' => '邮箱',
        'password' => '密码',
        'full_name' => '姓名',
        'wallet_address' => '钱包地址',
        'employment_status' => '雇佣状态',
        'start_date' => '入职日期',
        'pay_cycle' => '发薪周期',
        'currency' => '币种',
        'provision_portal_account' => '员工门户账号开关',
        'title' => '合同标题',
        'effective_date' => '生效日期',
        'status' => '状态',
        'contract_pdf' => '合同 PDF',
        'new_amount' => '金额',
        'reason' => '原因',
        'period' => '薪资周期',
        'due_date' => '截止日期',
    ],
    'custom' => [
        'new_amount' => [
            'regex' => '金额格式不正确。',
        ],
        'period' => [
            'date_format' => '薪资周期格式必须为 YYYY-MM。',
        ],
    ],
];
