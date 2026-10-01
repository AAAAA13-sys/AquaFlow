<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'series_name' => ['required', 'string', 'max:50'],
            'horizon_type' => ['sometimes', 'in:Daily,Weekly,Monthly'],
            'historical' => ['required', 'array', 'min:1'],
            'historical.*' => ['numeric'],
            'forecasted' => ['required', 'array', 'min:1'],
            'forecasted.*' => ['numeric'],
            'model_order' => ['sometimes', 'string', 'max:30'],
            'method' => ['sometimes', 'string', 'max:40'],
            'differencing' => ['sometimes', 'integer', 'min:0', 'max:2'],
            'aic' => ['sometimes', 'numeric'],
            'mape' => ['sometimes', 'numeric'],
            'mae' => ['sometimes', 'numeric'],
            'rmse' => ['sometimes', 'numeric'],
            'adf_statistic' => ['sometimes', 'numeric'],
            'adf_pvalue' => ['sometimes', 'numeric'],
            'ljung_box_pvalue' => ['sometimes', 'numeric'],
            'residual_std' => ['sometimes', 'numeric'],
        ];
    }
}
