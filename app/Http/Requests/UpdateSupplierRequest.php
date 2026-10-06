<?php

namespace App\Http\Requests;

class UpdateSupplierRequest extends StoreSupplierRequest
{
    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        foreach ($rules as &$fieldRules) {
            $fieldRules = array_values(array_diff($fieldRules, ['required']));
            if (! in_array('sometimes', $fieldRules, true)) {
                array_unshift($fieldRules, 'sometimes');
            }
        }

        return $rules;
    }
}
