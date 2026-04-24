<?php

return [
    'required' => ':attribute为必填项。',
    'string' => ':attribute必须是字符串。',
    'email' => ':attribute格式不正确。',
    'date' => ':attribute必须是有效日期。',
    'date_format' => ':attribute格式不正确。',
    'file' => ':attribute必须是文件。',
    'mimes' => ':attribute必须是类型为 :values 的文件。',
    'regex' => ':attribute格式不正确。',
    'boolean' => ':attribute必须为 true 或 false。',
    'unique' => ':attribute已被占用。',
    'in' => ':attribute必须是以下值之一：:values。',
    'max' => [
        'string' => ':attribute不能超过 :max 个字符。',
        'file' => ':attribute不能大于 :max KB。',
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
        'period' => '工资周期',
        'due_date' => '截止日期',
    ],
    'custom' => [
        'new_amount' => [
            'regex' => '金额格式不正确。',
        ],
        'period' => [
            'date_format' => '工资周期格式必须为 YYYY-MM。',
        ],
    ],
];
