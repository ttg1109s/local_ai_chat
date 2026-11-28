<?php
/** Semantic validation rules for selected tools. */
return [
  'python_dataframe'=>[
   ['id'=>'DATAFRAME_OPERATION_COLUMN','stage'=>'semantic','description'=>'For filter and groupby operations, provide the column field.','fields'=>['operation','column']],
   ['id'=>'DATAFRAME_GROUPBY_VALUE_COLUMN','stage'=>'semantic','description'=>'For groupby aggregations other than count, provide value_column.','fields'=>['operation','aggregation','value_column']]
  ],
  'python_generate_number'=>[
   ['id'=>'GENERATED_NUMBER_PARAMETERS','stage'=>'semantic','description'=>'Operation-specific numeric parameters must be valid; random distributions, vectors/matrices, deterministic sequences, sampling, and transforms are validated separately.','fields'=>['operation','distribution','shape','count','rows','columns','min','max','mean','std','probability','trials','lambda','scale','alpha','beta','mode','number_type','sequence_type','start','stop','step','ratio','base','first','second','coefficients','population','weights','replace','sampling_method','values','transform_type','factor','offset','digits','target_min','target_max']]
  ],
  'python_statistics'=>[
   ['id'=>'STATISTICS_METHOD_INPUTS','stage'=>'semantic','description'=>'Required values, y, groups, expected, or population_mean depend on method; provide the inputs required by the selected method.','fields'=>['method','values','y','groups','expected','population_mean']]
  ],
  'python_plot'=>[
   ['id'=>'PLOT_SERIES_COMPATIBILITY','stage'=>'semantic','description'=>'Multi-series input supports line and bar charts only; do not send both series and y; each x array must match its values length.','fields'=>['chart_type','series','x','y']]
  ]
];
